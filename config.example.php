<?php
  /**
   * The IMAP server connection string.
   *
   * This string should be in the format described on PHP's `imap_open`
   * documentation page, but should contain only connectivity information and
   * lack the mailbox name. If there is no configured mailbox name (see below),
   * then a list of mailboxes will be printed when the application is started.
   *
   * @var string
   */
  $GLOBALS['imap_server'] = '{imap.gmail.com:993/imap/ssl}';

  /**
   * The username used to login to the IMAP connection.
   *
   * @var string
   */
  $GLOBALS['imap_username'] = 'example@gmail.com';

  /**
   * The password used to login to the IMAP connection.
   *
   * @var string
   */
  $GLOBALS['imap_password'] = 'Ex4mple!';

  /**
   * The name of the IMAP mailbox to use for DMARC aggregate reports.
   *
   * It is assumed that each e-mail in this mailbox will contain an XML
   * (plain-text eXtensible Markup Language) or XML.GZ (g-zipped XML) attachment
   * that is compliant with RFC 7489 Appendix C. If any given message fails to
   * meet these conditions, it will be discarded during processing.
   *
   * @var string
   */
  $GLOBALS['imap_mailbox'] = 'INBOX';

  /**
   * Optionally filter messages in the configured mailbox by recipient.
   *
   * This filter could be useful if DMARC reports are mixed-in with other
   * messages, but are sent using a recipient delimiter (i.e.
   * `example+rua@gmail.com`).
   *
   * If this configuration directive is an empty string, this feature will be
   * disabled. Otherwise, this directive will reduce the result set of messages
   * to those with recipient addresses that match this directive exactly.
   *
   * @var string
   */
  $GLOBALS['imap_filter_recipient'] = 'example+rua@gmail.com';

  /**
   * Flag to determine if only unread messages should be processed.
   *
   * If this flag is `false`, all messages in the remote mailbox, including
   * those that could have been processed already, will be processed. This flag,
   * if `true`, helps track incremental progress.
   *
   * @var bool
   */
  $GLOBALS['imap_flag_unseen_only'] = true;
