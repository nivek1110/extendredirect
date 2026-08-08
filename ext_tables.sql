CREATE TABLE sys_redirect (
  broken_redirect tinyint(1) unsigned DEFAULT '0' NOT NULL,
	redirect_count varchar(255) DEFAULT '0' NOT NULL,
);

CREATE TABLE tx_twtredirect_disable_log (
  uid int(11) unsigned NOT NULL auto_increment,
  pid int(11) unsigned DEFAULT '0' NOT NULL,
  crdate int(11) unsigned DEFAULT '0' NOT NULL,
  redirect_uid int(11) unsigned DEFAULT '0' NOT NULL,
  source_host varchar(255) DEFAULT '' NOT NULL,
  source_path varchar(2048) DEFAULT '' NOT NULL,
  target varchar(2048) DEFAULT '' NOT NULL,
  status_code varchar(255) DEFAULT '' NOT NULL,
  reason text,

  PRIMARY KEY (uid),
  KEY redirect_uid (redirect_uid)
);

