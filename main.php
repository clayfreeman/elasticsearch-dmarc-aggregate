<?php
  /**
   * Fetches DMARC aggregate reports using IMAP and indexes each record using
   * Elastic Search.
   *
   * @copyright  Copyright 2018 Clay Freeman. All rights reserved.
   * @license    GNU Lesser General Public License v3 (LGPL-3.0).
   */

  ////////////////////////////////////////////////////////////////////////////
  ///////////////////// SECTION 0: Runtime Configuration /////////////////////
  ////////////////////////////////////////////////////////////////////////////

  // Define a regular expression to filter compatible attachment filenames
  define('FILENAME_REGEX', '/^(.+?)\\.'.
    '(?P<extension>(?:(?:xml|zip|tar|gz|bz2))'.
    '(?:\\.(?:gz|bz2))?)$/i');

  // Load the Composer dependency autoload file
  require_once(implode(DIRECTORY_SEPARATOR,
    [__DIR__, 'vendor', 'autoload.php']));

  // Load the configuration file for this script
  require_once(implode(DIRECTORY_SEPARATOR,
    [__DIR__, 'config.php']));

  ////////////////////////////////////////////////////////////////////////////
  //////////////////////// SECTION 1: IMAP Connection ////////////////////////
  ////////////////////////////////////////////////////////////////////////////

  // Attempt to connect to the IMAP server using the configured details
  $imap = imap_open($GLOBALS['imap_server'], $GLOBALS['imap_username'],
    $GLOBALS['imap_password'], OP_HALFOPEN);
  // Determine if the connection attempt was successful
  if (!is_resource($imap)) {
    throw new \Exception('Could not connect to IMAP server. Are the '.
    'configured credentials correct?');
  }

  // Determine whether we should list available mailboxes or open a mailbox
  $mailbox = $GLOBALS['imap_mailbox'] ?? '';
  if (is_string($mailbox) && strlen($mailbox) > 0) {
    // Attempt to re-open the IMAP connection to the specified mailbox
    if (!imap_reopen($imap, $GLOBALS['imap_server'].$mailbox)) {
      throw new \Exception('Could not switch mailbox. Does the configured '.
        'mailbox exist?');
    }
  } else {
    // Attempt to fetch a list of mailboxes on the remote server
    $mailboxes = imap_list($imap, $GLOBALS['imap_server'], '*');
    if (!is_array($mailboxes)) {
      throw new \Exception('Could not fetch a list of mailboxes.');
    }
    // Print the list of mailboxes
    echo "Available mailboxes:\r\n";
    foreach ($mailboxes as $mailbox) {
      echo '  - '.var_export(substr($mailbox,
        strlen($GLOBALS['imap_server'])), true)."\r\n";
    } exit(0);
  }

  ////////////////////////////////////////////////////////////////////////////
  ///////////////////////// SECTION 2: Message Query /////////////////////////
  ////////////////////////////////////////////////////////////////////////////

  // Build an IMAP search string based on the configuration
  $search = implode(' ', array_filter([ 'ALL',
    // Determine whether we should fetch all messages or only unseen
    ($GLOBALS['imap_flag_unseen_only'] ? 'UNSEEN' : false),
    // Determine if we should limit messages by recipient
    ($GLOBALS['imap_filter_recipient'] ? 'TO "'.str_replace('"', null,
     $GLOBALS['imap_filter_recipient']).'"'       : false)
  ]));
  // Perform the IMAP search so that the results can be processed
  $messages = imap_search($imap, $search, SE_UID, 'UTF-8') ?: [];

  ////////////////////////////////////////////////////////////////////////////
  ///////////////////// SECTION 3: Attachment Extraction /////////////////////
  ////////////////////////////////////////////////////////////////////////////

  // Construct an instance of the MIME message parser
  $parser = new \ZBateson\MailMimeParser\MailMimeParser();
  // Fetch and parse each individual message for applicable attachments
  $atts = array_merge([], ...array_map(function($id) use (&$imap, &$parser) {
    // Fetch and parse the raw message source using the provided UID
    $msg = $parser->parse(imap_fetchbody($imap, $id, '', FT_UID));
    // Merge each attachment descriptor array into a single associative array
    return array_filter(array_map(function($att) {
      // Use the attachment file name as the index for this array
      $name = $att->getHeaderParameter('Content-Disposition', 'filename', '');
      // Fetch the content of the attachment for the value of the entry
      $content = $att->getContent();
      // Fetch information about this attachment from its file name
      @preg_match(FILENAME_REGEX, $name, $info); $info = $info ?? [];
      // Process the information about the attachment
      $info = (object)array_filter($info, 'is_string', ARRAY_FILTER_USE_KEY);
      // Return an attachment descriptor fully describing the file
      return (object)['content' => $content, 'info' => $info, 'name' => $name];
      // Filter each attachment descriptor by file name for compatibility
    }, $msg->getAllAttachmentParts()), function($att) use (&$id) {
      // Check if the attachment name conforms to the RFC (relaxed)
      $exists = property_exists($att->info, 'extension');
      // Log a message if this attachment is being filtered
      if (!$exists) {
        unset($att->content);
        trigger_error('Skipping attachment: '.var_export($att, true).' '.
          'Message ID: '.$id.' Reason: File extension not found.');
      }
      return $exists;
    });
  }, $messages));

  ////////////////////////////////////////////////////////////////////////////
  ///////////////////// SECTION 4: Attachment Processing /////////////////////
  ////////////////////////////////////////////////////////////////////////////

  // Reduce the array of attachments to an array of records
  $records = array_merge([], ...array_filter(array_map(function($att) {
    // Force the file's extension to lowercase (for reliability)
    $att->info->extension = strtolower($att->info->extension);
    // Determine whether this attachment requires decompression
    if ($att->info->extension !== 'xml') {
      // Generate a random file name for temporary use
      $path = implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(),
        bin2hex(random_bytes(16)).'.'.$att->info->extension]);
      // Write the current attachment content to the temporary file
      file_put_contents($path, $att->content);
      // Attempt to open the temporary file with UnifiedArchive
      $archive = \wapmorgan\UnifiedArchive\UnifiedArchive::open($path);
      // Ensure that a valid instance of UnifiedArchive was returned
      if (count($files = $archive->getFileNames()) > 0 &&
          // Find the path of the first XML member in this archive
          ($xml = array_slice(array_filter($files, function($path) {
            return preg_match('/\\.xml$/i', $path);
          }), 0, 1)[0] ?? null) !== null) {
        // Assign the contents of the first XML file
        $att->content = $archive->getFileContent($xml);
      }
      // Remove the temporary file from disk
      unlink($path);
    }
    try {
      // Augment the content by parsing it with SimpleXMLElement
      $att->content = new \SimpleXMLElement($att->content);
      // Create some local shortcuts to long hierarchies
      $metadata = $att->content->report_metadata             ?: new stdClass;
      $date     = $att->content->report_metadata->date_range ?: new stdClass;
      $policy   = $att->content->policy_published            ?: new stdClass;
      // Assemble a base array from generic report information
      $base = [
        'org_name'           => (string)$metadata->org_name           ?: null,
        'email'              => (string)$metadata->email              ?: null,
        'extra_contact_info' => (string)$metadata->extra_contact_info ?: null,
        'report_id'          => (string)$metadata->report_id          ?: null,
        'begin_timestamp'    => (int)   $date->begin * 1000           ?: null,
        'end_timestamp'      => (int)   $date->end   * 1000           ?: null,
        'domain'             => (string)$policy->domain               ?: null,
        'adkim'              => (string)$policy->adkim                ?: null,
        'aspf'               => (string)$policy->aspf                 ?: null,
        'p'                  => (string)$policy->p                    ?: null,
        'sp'                 => (string)$policy->sp                   ?: null,
        'pct'                => (string)$policy->pct                  ?: null,
      ];
      // Iterate over each row in the record
      $results = [];
      foreach ($att->content->record as $item) {
        // Create some local shortcuts to long hierarchies
        $row              = $item->row;
        $policy_evaluated = $row ->policy_evaluated;
        $identifiers      = $item->identifiers;
        $auth_results     = $item->auth_results;
        // Merge the following record array with the base array
        $results[] = array_merge($base, [
          'source_ip'     => (string)$row->source_ip                ?: null,
          'count'         => (int)   $row->count                    ?: null,
          'disposition'   => (string)$policy_evaluated->disposition ?: null,
          'dkim'          => (string)$policy_evaluated->dkim        ?: null,
          'spf'           => (string)$policy_evaluated->spf         ?: null,
          'reason'        => (string)$policy_evaluated->reason      ?: null,
          'envelope_to'   => (string)$identifiers->envelope_to      ?: null,
          'envelope_from' => (string)$identifiers->envelope_from    ?: null,
          'header_from'   => (string)$identifiers->header_from      ?: null,
          'auth_results'  => $auth_results                          ?: []
        ]);
      }
      // Return the record result set for this attachment
      return $results;
    } catch (\Exception $e) {}
    // If we've reached this point, we're skipping this attachment
    unset($att->content);
    trigger_error('Skipping attachment: '.json_encode($att));
    return false;
  }, $atts)));

  ////////////////////////////////////////////////////////////////////////////
  ///////////////////////// SECTION 5: Index Records /////////////////////////
  ////////////////////////////////////////////////////////////////////////////

  // Build an instance used to interface with Elastic Search over cURL
  $client = \Elasticsearch\ClientBuilder::create()->build();

  // Create a list of indices based on the end timestamp of each record
  $dates = array_unique(array_map(function($record) {
    return 'dmarc-'.date('Y.m.d', $record['end_timestamp'] / 1000);
  }, $records));

  // Define a list of columns separated by column type for building a schema
  $schema = [
    'keyword' => ['adkim', 'aspf', 'p', 'sp', 'disposition', 'dkim', 'spf'],
    'long'    => ['pct', 'count'],
    'date'    => ['begin_timestamp', 'end_timestamp'],
    'ip'      => ['source_ip'],
    'object'  => ['auth_results']
  ];
  // Map each column to a type-specific schema subset
  foreach ($schema as $type => &$columns) {
    // Create the schema subset for this type by specifying the column type
    $columns = array_merge([], ...array_map(function($column) use ($type) {
      return [$column => ['type' => $type]];
    }, $columns));
  }
  // Merge the schema subsets into the final schema
  $schema = array_merge([], ...array_values($schema));

  // Iterate over the list of indices that should exist before indexing records
  foreach ($dates as $date) {
    // Ensure that the required index is not yet defined before defining it
    if (!$client->indices()->getMapping(['index' => $date, 'type' => 'doc'])) {
      // Create the required index using the pre-build schema
      $client->indices()->create([
        'index' => $date,
        'body'  => ['mappings' => ['doc' => ['properties' => $schema]]]
      ]);
    }
  }

  // Iterate over each record to be indexed in Elastic Search
  foreach ($records as $record) {
    // Attempt to index the record
    $response = $client->index([
      'index' => 'dmarc-'.date('Y.m.d', $record['begin_timestamp'] / 1000),
      'type'  => 'doc',
      'body'  => $record
    ]);
    // Check the response result to see if the record was indexed
    if ($response['result'] !== 'created') {
      trigger_error('Unable to index record: '.var_export($record, true).' '.
        'Reason: '.var_export($response, true));
    }
  }
