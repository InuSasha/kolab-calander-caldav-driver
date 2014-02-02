kolab-calander-caldav-driver
============================

# 1 - What is kolab-calander-caldav-driver

The roundcube webmail-client have calendar plugin implemented by the Kolab-Project.
This calendar only have a database, google and spezial kolab-backend event storage.
In this project a additional backend is developed, with support for access an CalDAV server like <a href="http://baikal-server.com">Baikal</a>

# 2 - Installation:

### 1.1 - Source install
under the driver directory of the calendar plugin run:
```sh
git clone https://github.com/InuSasha/kolab-calander-caldav-driver.git caldav
```

### 1.2 - Configuration
change the bankend driver in config.inc.php (of the calendar plugin) to "caldav"
```php
// backend type (database, google, kolab)
$rcmail_config['calendar_driver'] = "caldav";
```

add the CalDAV Backend path to the end of the config.inc.php
```php
// Base URL to build fully qualified URIs to access calendars via CALDAV
// The following replacement variables are supported:
// %h - Current HTTP host
// %u - Current webmail user name
// %n - Calendar name
// %i - Calendar UUID
$rcmail_config['calendar_caldav_url'] = 'http://%h/cal.php/calendars/%u';
```
Note: only %h and %u are supported

# 3 - open Things

see Issue-List: <a href="https://github.com/InuSasha/kolab-calander-caldav-driver/issues?labels=ToDos&page=1&state=open"> ToDos</a>
Help is always willcome
