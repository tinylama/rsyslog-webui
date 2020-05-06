# Web UI for rsyslog

Requirements
---
* Ensure you have http server i.e. Apache with PHP and MYSQL installed and working.
* You will need to have rsyslog storing logs in mysql, so install **rsyslog** and **rsyslog-mysql**
* Create a table for syslog entries and user that can SELECT, INSERT, UPDATE, DELETE, FILE for that table only
* Add the following to /etc/rsyslog.conf to enable logs to be stored in mysql
```
    $ModLoad ommysql
    *.* :ommysql:127.0.0.1,SyslogTableName,SQLUSER,SQLPASSWORD
```
* Restart the rsyslog service

Installation
---
* Clone from github
`git clone "https://github.com/Tiny-Lama/rsyslog-webui.git" /var/www/html/syslog-ui

* Create the required config from the template
`cp /var/www/html/syslog/config-template.php /var/www/html/syslog/config.php

* Modify your config file, this is where you will need your mysql database details.
` sudo nano /var/www/html/syslog/config.php

* Create a scheduled task for database clean-up. Open crontab
`sudo crontab -e:

* Modify crontab by pasting this at the end of the file and save (CTRL+O).
```
  1 0 * * * cd /var/www/html/maintenance; /usr/bin/php ./db-maintenance.php
```

Then test in your web browser:
http://localhost/syslog-ui

## Mobile:
![Mobile](/images/mobile-screenshot.jpg?raw=true "Mobile")

## Desktop:
![Desktop](/images/screenshot.png?raw=true "Desktop")

[Original Code by hmsdao](https://github.com/hmsdao/bootstrap-rsyslog-ui)
