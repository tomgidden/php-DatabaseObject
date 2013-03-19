/* This is a basic schema with some sample data. */

DROP TABLE IF EXISTS company;
CREATE TABLE company (
  company_id int(11) NOT NULL,
  company_name varchar(255) default NULL,
  PRIMARY KEY  (company_id),
  KEY company_name (company_name)
);

INSERT INTO company (company_id, company_name) VALUES (1,'Foo.com Limited');




DROP TABLE IF EXISTS person;
CREATE TABLE person (
  person_id int(11) NOT NULL,
  first_name varchar(255) default NULL,
  last_name varchar(255) default NULL,
  company_id int(11) default NULL,
  PRIMARY KEY  (person_id),
  KEY last_name (last_name,first_name),
  KEY first_name (first_name,last_name),
  KEY company_id (company_id)
);

INSERT INTO person (person_id, first_name, last_name, company_id) VALUES (9,'Tom','Gidden',1);
INSERT INTO person (person_id, first_name, last_name, company_id) VALUES (10,'Steve','Jones',1);
INSERT INTO person (person_id, first_name, last_name, company_id) VALUES (11,'Joe','Public',1);




DROP TABLE IF EXISTS contact_detail;
CREATE TABLE contact_detail (
  person_id int(11) NOT NULL,
  contact_type varchar(255) NOT NULL default '',
  value varchar(255) default NULL,
  PRIMARY KEY  (person_id,contact_type)
);

LOCK TABLES contact_detail WRITE;
INSERT INTO contact_detail (person_id, contact_type, value) VALUES (9,'Email','tom@example.net');
INSERT INTO contact_detail (person_id, contact_type, value) VALUES (9,'Tel','07976 123 456');
INSERT INTO contact_detail (person_id, contact_type, value) VALUES (9,'Ext','123');
INSERT INTO contact_detail (person_id, contact_type, value) VALUES (10,'Email','steve@example.com');
INSERT INTO contact_detail (person_id, contact_type, value) VALUES (10,'Tel','07976 987 654');
INSERT INTO contact_detail (person_id, contact_type, value) VALUES (10,'Ext','321');
INSERT INTO contact_detail (person_id, contact_type, value) VALUES (11,'Email','joe.q.public@example.com');
INSERT INTO contact_detail (person_id, contact_type, value) VALUES (11,'Tel','07123 456 789');
INSERT INTO contact_detail (person_id, contact_type, value) VALUES (11,'Ext','666');
