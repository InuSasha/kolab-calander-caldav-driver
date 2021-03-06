<?php

/**
 * CalDAV driver for the Calendar plugin
 *
 * @version @package_version@
 * @author Sascha Kuehndel <dev@inusasha.de>
 * 
 * based on the database driver from
 * Lazlo Westerhof <hello@lazlo.me>, Thomas Bruederli <bruederli@kolabsys.com>
 * and caldav driver from
 * Jean-Louis Dupond <jean-louis@dupond.be>
 *
 * Copyright (C) 2014, Sascha Kuehndel
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class caldav_driver extends calendar_driver
{
  // constants
  const DEFAULT_COLOR = 'cc0000';
  const SQL_TABLE     = 'caldav_calendars';

  // features this backend supports
  public $alarms      = false;
  public $attendees   = false;
  public $freebusy    = false;
  public $attachments = false;
  public $alarm_types = array('DISPLAY');

  private $rc;
  private $cal;
  private $cache = array();
  private $calendars = array();
  private $calendar_ids = '';
  private $free_busy_map = array('free' => 0, 'busy' => 1, 'out-of-office' => 2, 'outofoffice' => 2, 'tentative' => 3);
  private $sensitivity_map = array('public' => 0, 'private' => 1, 'confidential' => 2);
  private $server_timezone;
  
  private $caldav;
  private $caldav_url;

  /**
   * Default constructor
   */
  public function __construct($cal)
  {
    $this->cal = $cal;
    $this->rc = $cal->rc;
    $this->server_timezone = new DateTimeZone(date_default_timezone_get());

    // load library classes
    require_once(dirname(__FILE__) . '/caldav-client-v2.php');
    require_once('/usr/share/awl/inc/iCalendar.php');
    
    // read caldav config
    $this->caldav_url = $this->rc->config->get('calendar_caldav_url');
    $this->caldav_url = str_replace('%h', $_SERVER['HTTP_HOST'], $this->caldav_url);
    $this->caldav_url = str_replace('%u', $_SESSION['username'], $this->caldav_url);

    // Open CalDAV connection
    $this->caldav = new CalDAVClient($this->caldav_url, $_SESSION['username'], $this->rc->decrypt($_SESSION['password']));
    $this->_read_calendars();
  }

  /**
   * Get a list of available calendars from this source
   *
   * @param bool $active   Return only active calendars
   * @param bool $personal Return only personal calendars
   *
   * @return array List of calendars
   */
  public function list_calendars($active = false, $personal = false)
  {
    /* TODO implement
    // attempt to create a default calendar for this user
    if (empty($this->calendars)) {
      if ($this->create_calendar(array('name' => 'Default', 'color' => 'cc0000')))
        $this->_read_calendars();
    } 
   */

    $calendars = $this->calendars;

    // filter active calendars
    if ($active) {
      foreach ($calendars as $idx => $cal) {
        if (!$cal['active']) {
          unset($calendars[$idx]);
        }
      }
    }

    // 'personal' is unsupported in this driver
    
    // append the virtual birthdays calendar
    if ($this->rc->config->get('calendar_contact_birthdays', false)) {
      $prefs = $this->rc->config->get('birthday_calendar', array('color' => '87CEFA'));
      $hidden = array_filter(explode(',', $this->rc->config->get('hidden_calendars', '')));

      $id = self::BIRTHDAY_CALENDAR_ID;
      if (!$active || !in_array($id, $hidden)) {
        $calendars[$id] = array(
          'id'         => $id,
          'name'       => $this->cal->gettext('birthdays'),
          'listname'   => $this->cal->gettext('birthdays'),
          'color'      => $prefs['color'],
          'showalarms' => (bool)$this->rc->config->get('calendar_birthdays_alarm_type'),
          'active'     => !in_array($id, $hidden),
          'class_name' => 'birthdays',
          'readonly'   => true,
          'default'    => false,
          'children'   => false,
        );
      }
    }

    return $calendars;
  }

  /** TODO: implement
   * Create a new calendar assigned to the current user
   *
   * @param array Hash array with calendar properties
   *    name: Calendar name
   *   color: The color of the calendar
   * @return mixed ID of the calendar on success, False on error
   */
  public function create_calendar($prop)
  {
    /*
    $result = $this->rc->db->query(
      "INSERT INTO " . $this->db_calendars . "
       (user_id, name, color, showalarms)
       VALUES (?, ?, ?, ?)",
       $this->rc->user->ID,
       $prop['name'],
       $prop['color'],
       $prop['showalarms']?1:0
    );
    
    if ($result)
      return $this->rc->db->insert_id($this->db_calendars);
    */
    return false;
  }

  /**
   * Update properties of an existing calendar
   *
   * @see calendar_driver::edit_calendar()
   */
  public function edit_calendar($prop)
  {
    // birthday calendar properties are saved in user prefs
    if ($prop['id'] == self::BIRTHDAY_CALENDAR_ID) {
      $prefs['birthday_calendar'] = $this->rc->config->get('birthday_calendar', array('color' => '87CEFA'));
      if (isset($prop['color']))
        $prefs['birthday_calendar']['color'] = $prop['color'];
      if (isset($prop['showalarms']))
        $prefs['calendar_birthdays_alarm_type'] = $prop['showalarms'] ? $this->alarm_types[0] : '';
      $this->rc->user->save_prefs($prefs);
      return true;
    }

    // change property of calendar
    $rows = 0;
    if ($prop['name'] !== $this->calendars[$prop['id']]['name'])
    {
      $query = $this->rc->db->query(
        'UPDATE '.self::SQL_TABLE.' SET displayname=? WHERE server_id=? AND calendar_id=?',
        $prop['name'],
        $this->caldav_url,
        $prop['id']
      );
      $rows = $this->rc->db->affected_rows($query);
    }
    $query = $this->rc->db->query(
      'UPDATE '.self::SQL_TABLE.' SET color=? WHERE server_id=? AND calendar_id=?',
      $prop['color'],
      $this->caldav_url,
      $prop['id']
    );
    $rows += $this->rc->db->affected_rows($query);

    return $rows != 0;
  }

  /** TODO: implement
   * Set active/subscribed state of a calendar
   * Save a list of hidden calendars in user prefs
   *
   * @see calendar_driver::subscribe_calendar()
   */
  public function subscribe_calendar($prop)
  {
    /*
    $hidden = array_flip(explode(',', $this->rc->config->get('hidden_calendars', '')));
    
    if ($prop['active'])
      unset($hidden[$prop['id']]);
    else
      $hidden[$prop['id']] = 1;
    
    return $this->rc->user->save_prefs(array('hidden_calendars' => join(',', array_keys($hidden))));
    */
  }

  /** TODO: implement
   * Delete the given calendar with all its contents
   *
   * @see calendar_driver::remove_calendar()
   */
  public function remove_calendar($prop)
  {
    /*
    if (!$this->calendars[$prop['id']])
      return false;

    // events and attachments will be deleted by foreign key cascade

    $query = $this->rc->db->query(
      "DELETE FROM " . $this->db_calendars . "
       WHERE calendar_id=?",
       $prop['id']
    );

    return $this->rc->db->affected_rows($query);
    */
  }

  /** TODO: implement
   * Add a single event to the database
   *
   * @param array Hash array with event properties
   * @see calendar_driver::new_event()
   */
  public function new_event($event)
  {
    /*
    if (!$this->validate($event))
      return false;

    if (!empty($this->calendars)) {
      if ($event['calendar'] && !$this->calendars[$event['calendar']])
        return false;
      if (!$event['calendar'])
        $event['calendar'] = reset(array_keys($this->calendars));

      $event = $this->_save_preprocess($event);

      $this->rc->db->query(sprintf(
        "INSERT INTO " . $this->db_events . "
         (calendar_id, created, changed, uid, %s, %s, all_day, recurrence, title, description, location, categories, url, free_busy, priority, sensitivity, attendees, alarms, notifyat)
         VALUES (?, %s, %s, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
          $this->rc->db->quote_identifier('start'),
          $this->rc->db->quote_identifier('end'),
          $this->rc->db->now(),
          $this->rc->db->now()
        ),
        $event['calendar'],
        strval($event['uid']),
        $event['start']->format(self::DB_DATE_FORMAT),
        $event['end']->format(self::DB_DATE_FORMAT),
        intval($event['all_day']),
        $event['_recurrence'],
        strval($event['title']),
        strval($event['description']),
        strval($event['location']),
        join(',', (array)$event['categories']),
        strval($event['url']),
        intval($event['free_busy']),
        intval($event['priority']),
        intval($event['sensitivity']),
        $event['attendees'],
        $event['alarms'],
        $event['notifyat']
      );

      $event_id = $this->rc->db->insert_id($this->db_events);

      if ($event_id) {
        $event['id'] = $event_id;

        // add attachments
        if (!empty($event['attachments'])) {
          foreach ($event['attachments'] as $attachment) {
            $this->add_attachment($attachment, $event_id);
            unset($attachment);
          }
        }

        $this->_update_recurring($event);
      }

      return $event_id;
    }
    */
    return false;
  }

  /** TODO: implement
   * Update an event entry with the given data
   *
   * @param array Hash array with event properties
   * @see calendar_driver::edit_event()
   */
  public function edit_event($event)
  {
    /*
    if (!empty($this->calendars)) {
      $update_master = false;
      $update_recurring = true;
      $old = $this->get_event($event);
      
      // increment sequence number
      if ($old['sequence'] && empty($event['sequence']))
        $event['sequence'] = max($event['sequence'], $old['sequence']+1);
      
      // modify a recurring event, check submitted savemode to do the right things
      if ($old['recurrence'] || $old['recurrence_id']) {
        $master = $old['recurrence_id'] ? $this->get_event(array('id' => $old['recurrence_id'])) : $old;
        
        // keep saved exceptions (not submitted by the client)
        if ($old['recurrence']['EXDATE'])
          $event['recurrence']['EXDATE'] = $old['recurrence']['EXDATE'];
        
        switch ($event['_savemode']) {
          case 'new':
            $event['uid'] = $this->cal->generate_uid();
            return $this->new_event($event);
          
          case 'current':
            // add exception to master event
            $master['recurrence']['EXDATE'][] = $old['start'];
            $update_master = true;
            
            // just update this occurence (decouple from master)
            $update_recurring = false;
            $event['recurrence_id'] = 0;
            $event['recurrence'] = array();
            break;
          
          case 'future':
            if ($master['id'] != $event['id']) {
              // set until-date on master event, then save this instance as new recurring event
              $master['recurrence']['UNTIL'] = clone $event['start'];
              $master['recurrence']['UNTIL']->sub(new DateInterval('P1D'));
              unset($master['recurrence']['COUNT']);
              $update_master = true;
              
              // if recurrence COUNT, update value to the correct number of future occurences
              if ($event['recurrence']['COUNT']) {
                $fromdate = clone $event['start'];
                $fromdate->setTimezone($this->server_timezone);
                $sqlresult = $this->rc->db->query(sprintf(
                  "SELECT event_id FROM " . $this->db_events . "
                   WHERE calendar_id IN (%s)
                   AND %s >= ?
                   AND recurrence_id=?",
                  $this->calendar_ids,
                  $this->rc->db->quote_identifier('start')
                  ),
                  $fromdate->format(self::DB_DATE_FORMAT),
                  $master['id']);
                if ($count = $this->rc->db->num_rows($sqlresult))
                  $event['recurrence']['COUNT'] = $count;
              }
            
              $update_recurring = true;
              $event['recurrence_id'] = 0;
              break;
            }
            // else: 'future' == 'all' if modifying the master event
          
          default:  // 'all' is default
            $event['id'] = $master['id'];
            $event['recurrence_id'] = 0;
            
            // use start date from master but try to be smart on time or duration changes
            $old_start_date = $old['start']->format('Y-m-d');
            $old_start_time = $old['allday'] ? '' : $old['start']->format('H:i');
            $old_duration = $old['end']->format('U') - $old['start']->format('U');
            
            $new_start_date = $event['start']->format('Y-m-d');
            $new_start_time = $event['allday'] ? '' : $event['start']->format('H:i');
            $new_duration = $event['end']->format('U') - $event['start']->format('U');
            
            $diff = $old_start_date != $new_start_date || $old_start_time != $new_start_time || $old_duration != $new_duration;
            
            // shifted or resized
            if ($diff && ($old_start_date == $new_start_date || $old_duration == $new_duration)) {
              $event['start'] = $master['start']->add($old['start']->diff($event['start']));
              $event['end'] = clone $event['start'];
              $event['end']->add(new DateInterval('PT'.$new_duration.'S'));
            }
            // dates did not change, use the ones from master
            else if ($event['start'] == $old['start'] && $event['end'] == $old['end']) {
              $event['start'] = $master['start'];
              $event['end'] = $master['end'];
            }
            break;
        }
      }
      
      $success = $this->_update_event($event, $update_recurring);
      if ($success && $update_master)
        $this->_update_event($master, true);
      
      return $success;
    }
    */
    return false;
  }

  /** TODO: implement
   * Move a single event
   *
   * @param array Hash array with event properties
   * @see calendar_driver::move_event()
   */
  public function move_event($event)
  {
    /*
    // let edit_event() do all the magic
    return $this->edit_event($event + (array)$this->get_event($event));
    */
  }

  /** TODO: implement
   * Resize a single event
   *
   * @param array Hash array with event properties
   * @see calendar_driver::resize_event()
   */
  public function resize_event($event)
  {
    /*
    // let edit_event() do all the magic
    return $this->edit_event($event + (array)$this->get_event($event));
    */
  }

  /** TODO: implement
   * Remove a single event from the database
   *
   * @param array   Hash array with event properties
   * @param boolean Remove record irreversible (@TODO)
   *
   * @see calendar_driver::remove_event()
   */
  public function remove_event($event, $force = true)
  {
    /*
    if (!empty($this->calendars)) {
      $event += (array)$this->get_event($event);
      $master = $event;
      $update_master = false;
      $savemode = 'all';

      // read master if deleting a recurring event
      if ($event['recurrence'] || $event['recurrence_id']) {
        $master = $event['recurrence_id'] ? $this->get_event(array('id' => $event['recurrence_id'])) : $event;
        $savemode = $event['_savemode'];
      }

      switch ($savemode) {
        case 'current':
          // add exception to master event
          $master['recurrence']['EXDATE'][] = $event['start'];
          $update_master = true;
          
          // just delete this single occurence
          $query = $this->rc->db->query(
            "DELETE FROM " . $this->db_events . "
             WHERE calendar_id IN (" . $this->calendar_ids . ")
             AND event_id=?",
            $event['id']
          );
          break;

        case 'future':
          if ($master['id'] != $event['id']) {
            // set until-date on master event
            $master['recurrence']['UNTIL'] = clone $event['start'];
            $master['recurrence']['UNTIL']->sub(new DateInterval('P1D'));
            unset($master['recurrence']['COUNT']);
            $update_master = true;
            
            // delete this and all future instances
            $fromdate = clone $event['start'];
            $fromdate->setTimezone($this->server_timezone);
            $query = $this->rc->db->query(
              "DELETE FROM " . $this->db_events . "
               WHERE calendar_id IN (" . $this->calendar_ids . ")
               AND " . $this->rc->db->quote_identifier('start') . " >= ?
               AND recurrence_id=?",
              $fromdate->format(self::DB_DATE_FORMAT),
              $master['id']
            );
            break;
          }
          // else: future == all if modifying the master event

        default:  // 'all' is default
          $query = $this->rc->db->query(
            "DELETE FROM " . $this->db_events . "
             WHERE (event_id=? OR recurrence_id=?)
             AND calendar_id IN (" . $this->calendar_ids . ")",
             $master['id'],
             $master['id']
          );
          break;
      }

      $success = $this->rc->db->affected_rows($query);
      if ($success && $update_master)
        $this->_update_event($master, true);

      return $success;
    }
    */
    return false;
  }

  /** TODO: implement
   * Return data of a specific event
   * @param mixed  Hash array with event properties or event UID
   * @param boolean Only search in writeable calendars (ignored)
   * @param boolean Only search in active calendars
   * @param boolean Only search in personal calendars (ignored)
   * @return array Hash array with event properties
   */
  public function get_event($event, $writeable = false, $active = false, $personal = false)
  {
    /*
    $id = is_array($event) ? ($event['id'] ? $event['id'] : $event['uid']) : $event;
    $col = is_array($event) && is_numeric($id) ? 'event_id' : 'uid';

    if ($this->cache[$id])
      return $this->cache[$id];

    if ($active) {
      $calendars = $this->calendars;
      foreach ($calendars as $idx => $cal) {
        if (!$cal['active']) {
          unset($calendars[$idx]);
        }
      }
      $cals = join(',', $calendars);
    }
    else {
      $cals = $this->calendar_ids;
    }

    $result = $this->rc->db->query(sprintf(
      "SELECT e.*, (SELECT COUNT(attachment_id) FROM " . $this->db_attachments . " 
         WHERE event_id = e.event_id OR event_id = e.recurrence_id) AS _attachments
       FROM " . $this->db_events . " AS e
       WHERE e.calendar_id IN (%s)
       AND e.$col=?",
       $cals
      ),
      $id);

    if ($result && ($event = $this->rc->db->fetch_assoc($result)) && $event['event_id']) {
      $this->cache[$id] = $this->_read_postprocess($event);
      return $this->cache[$id];
    }
    */
    return false;
  }

  /**
   * Get event data
   *
   * @see calendar_driver::load_events()
   */
  public function load_events($start, $end, $query = null, $calendars = null, $virtual = 1, $modifiedsince = null)
  {
    $begin = gmdate("Ymd\THis\Z", $start);
    $finish = gmdate("Ymd\THis\Z", $end);

    if (empty($calendars))
      $calendars = array_keys($this->calendars);
    else if (is_string($calendars))
      $calendars = explode(',', $calendars);

    // only allow to select from calendars of this use
    //$calendar_ids = array_map(array($this->rc->db, 'quote'), array_intersect($calendars, array_keys($this->calendars)));

    $events = array();
    // iterate through calendars
    foreach ($calendars as $calendar)
    {
      //rcube::write_log("caldav","calendar:" . $calendar);
      $path = $this->caldav_url . $calendar . "/";
      
      // get all calendar files and iterate over
      $files = $this->caldav->GetEvents($begin, $finish, $path);
      foreach ($files as $file)
      {
        //rcube::write_log("caldav","calendar:" . $calendar . "->" . $file['href']);
        //rcube::write_log("caldav",json_encode($file));
        $ical = new iCalComponent($file['data']);
        
        //Get The Timezone
        $timezones = $ical->GetComponents("VTIMEZONE");
        $timezone = "";
        if (count($timezones) > 0)
          $timezone = $this->_ParseTimezone($timezones[0]->GetPValue("TZID"));
        if (!$timezone)
          $timezone = date_default_timezone_get();
        
        // get single vcards from file
        $vcards = $ical->GetComponents("VTIMEZONE", false);
        foreach ($vcards as $vcard)
          $events[] = $this->vcard2event($vcard, $file['href'], $calendar);
      }
    }
    $this->cache['id'] = $events;
    
    // add events from the address books birthday calendar
    if (in_array(self::BIRTHDAY_CALENDAR_ID, $calendars))
      $events = array_merge($events, $this->load_birthday_events($start, $end, $search, $modifiedsince));

    return $events;
  }

  /** TODO: implement
   * Get a list of pending alarms to be displayed to the user
   *
   * @see calendar_driver::pending_alarms()
   */
  public function pending_alarms($time, $calendars = null)
  {
    /*
    if (empty($calendars))
      $calendars = array_keys($this->calendars);
    else if (is_string($calendars))
      $calendars = explode(',', $calendars);
    
    // only allow to select from calendars with activated alarms
    $calendar_ids = array();
    foreach ($calendars as $cid) {
      if ($this->calendars[$cid] && $this->calendars[$cid]['showalarms'])
        $calendar_ids[] = $cid;
    }
    $calendar_ids = array_map(array($this->rc->db, 'quote'), $calendar_ids);
    
    $alarms = array();
    if (!empty($calendar_ids)) {
      $result = $this->rc->db->query(sprintf(
        "SELECT * FROM " . $this->db_events . "
         WHERE calendar_id IN (%s)
         AND notifyat <= %s AND %s > %s",
         join(',', $calendar_ids),
         $this->rc->db->fromunixtime($time),
         $this->rc->db->quote_identifier('end'),
         $this->rc->db->fromunixtime($time)
       ));

      while ($result && ($event = $this->rc->db->fetch_assoc($result)))
        $alarms[] = $this->_read_postprocess($event);
    }

    return $alarms;
    */
  }

  /** TODO: implement
   * Feedback after showing/sending an alarm notification
   *
   * @see calendar_driver::dismiss_alarm()
   */
  public function dismiss_alarm($event_id, $snooze = 0)
  {
    /*
    // set new notifyat time or unset if not snoozed
    $notify_at = $snooze > 0 ? date(self::DB_DATE_FORMAT, time() + $snooze) : null;
    
    $query = $this->rc->db->query(sprintf(
      "UPDATE " . $this->db_events . "
       SET   changed=%s, notifyat=?
       WHERE event_id=?
       AND calendar_id IN (" . $this->calendar_ids . ")",
        $this->rc->db->now()),
      $notify_at,
      $event_id
    );
    
    return $this->rc->db->affected_rows($query);
    */
  }

  /** TODO: implement
   * List attachments of specified event
   */
  public function list_attachments($event)
  {
    /*
    $attachments = array();

    if (!empty($this->calendar_ids)) {
      $result = $this->rc->db->query(
        "SELECT attachment_id AS id, filename AS name, mimetype, size " .
        " FROM " . $this->db_attachments .
        " WHERE event_id IN (SELECT event_id FROM " . $this->db_events .
          " WHERE event_id=?"  .
            " AND calendar_id IN (" . $this->calendar_ids . "))".
        " ORDER BY filename",
        $event['recurrence_id'] ? $event['recurrence_id'] : $event['event_id']
      );

      while ($result && ($arr = $this->rc->db->fetch_assoc($result))) {
        $attachments[] = $arr;
      }
    }

    return $attachments;
    */
  }

  /** TODO: implement
   * Get attachment properties
   */
  public function get_attachment($id, $event)
  {
    /*
    if (!empty($this->calendar_ids)) {
      $result = $this->rc->db->query(
        "SELECT attachment_id AS id, filename AS name, mimetype, size " .
        " FROM " . $this->db_attachments .
        " WHERE attachment_id=?".
          " AND event_id=?",
        $id,
        $event['recurrence_id'] ? $event['recurrence_id'] : $event['id']
      );

      if ($result && ($arr = $this->rc->db->fetch_assoc($result))) {
        return $arr;
      }
    }
    */
    return null;
  }

  /** TODO: implement
   * Get attachment body
   */
  public function get_attachment_body($id, $event)
  {
    /*
    if (!empty($this->calendar_ids)) {
      $result = $this->rc->db->query(
        "SELECT data " .
        " FROM " . $this->db_attachments .
        " WHERE attachment_id=?".
          " AND event_id=?",
        $id,
        $event['id']
      );

      if ($result && ($arr = $this->rc->db->fetch_assoc($result))) {
        return base64_decode($arr['data']);
      }
    }
    */
    return null;
  }

  /** TODO: implement
   * Remove the given category
   */
  public function remove_category($name)
  {
    /*
    $query = $this->rc->db->query(
      "UPDATE " . $this->db_events . "
       SET   categories=''
       WHERE categories=?
       AND   calendar_id IN (" . $this->calendar_ids . ")",
      $name
    );
    
    return $this->rc->db->affected_rows($query);
    */
  }

  /** TODO: implement
   * Update/replace a category
   */
  public function replace_category($oldname, $name, $color)
  {
    /*
    $query = $this->rc->db->query(
      "UPDATE " . $this->db_events . "
       SET   categories=?
       WHERE categories=?
       AND   calendar_id IN (" . $this->calendar_ids . ")",
      $name,
      $oldname
    );
    
    return $this->rc->db->affected_rows($query);
    */
  }

  /** TODO: needed
   * Convert save data to be used in SQL statements
   */
  private function _save_preprocess($event)
  {
    /*
    // shift dates to server's timezone (except for all-day events)
    if (!$event['allday']) {
      $event['start'] = clone $event['start'];
      $event['start']->setTimezone($this->server_timezone);
      $event['end'] = clone $event['end'];
      $event['end']->setTimezone($this->server_timezone);
    }
    
    // compose vcalendar-style recurrencue rule from structured data
    $rrule = $event['recurrence'] ? libcalendaring::to_rrule($event['recurrence']) : '';
    $event['_recurrence'] = rtrim($rrule, ';');
    $event['free_busy'] = intval($this->free_busy_map[strtolower($event['free_busy'])]);
    $event['sensitivity'] = intval($this->sensitivity_map[strtolower($event['sensitivity'])]);
    
    if (isset($event['allday'])) {
      $event['all_day'] = $event['allday'] ? 1 : 0;
    }
    
    // compute absolute time to notify the user
    $event['notifyat'] = $this->_get_notification($event);
    
    // process event attendees
    $_attendees = '';
    foreach ((array)$event['attendees'] as $attendee) {
      if (!$attendee['name'] && !$attendee['email'])
        continue;
      $_attendees .= 'NAME="'.addcslashes($attendee['name'], '"') . '"' .
        ';STATUS=' . $attendee['status'].
        ';ROLE=' . $attendee['role'] .
        ';EMAIL=' . $attendee['email'] .
        "\n";
    }
    $event['attendees'] = rtrim($_attendees);

    return $event;
    */
  }
  
  /** TODO: needed
   * Compute absolute time to notify the user
   */
  private function _get_notification($event)
  {
    /*
    if ($event['alarms'] && $event['start'] > new DateTime()) {
      $alarm = libcalendaring::get_next_alarm($event);

      if ($alarm['time'] && $alarm['action'] == 'DISPLAY')
        return date('Y-m-d H:i:s', $alarm['time']);
    }
    */
    return null;
  }

  /** TODO: needed
   * Save the given event record to database
   *
   * @param array Event data, already passed through self::_save_preprocess()
   * @param boolean True if recurring events instances should be updated, too
   */
  private function _update_event($event, $update_recurring = true)
  {
    /*
    $event = $this->_save_preprocess($event);
    $sql_set = array();
    $set_cols = array('start', 'end', 'all_day', 'recurrence_id', 'sequence', 'title', 'description', 'location', 'categories', 'url', 'free_busy', 'priority', 'sensitivity', 'attendees', 'alarms', 'notifyat');
    foreach ($set_cols as $col) {
      if (is_object($event[$col]) && is_a($event[$col], 'DateTime'))
        $sql_set[] = $this->rc->db->quote_identifier($col) . '=' . $this->rc->db->quote($event[$col]->format(self::DB_DATE_FORMAT));
      else if (is_array($event[$col]))
        $sql_set[] = $this->rc->db->quote_identifier($col) . '=' . $this->rc->db->quote(join(',', $event[$col]));
      else if (isset($event[$col]))
        $sql_set[] = $this->rc->db->quote_identifier($col) . '=' . $this->rc->db->quote($event[$col]);
    }
    
    if ($event['_recurrence'])
      $sql_set[] = $this->rc->db->quote_identifier('recurrence') . '=' . $this->rc->db->quote($event['_recurrence']);
    
    if ($event['_fromcalendar'] && $event['_fromcalendar'] != $event['calendar'])
        $sql_set[] = 'calendar_id=' . $this->rc->db->quote($event['calendar']);
    
    $query = $this->rc->db->query(sprintf(
      "UPDATE " . $this->db_events . "
       SET   changed=%s %s
       WHERE event_id=?
       AND   calendar_id IN (" . $this->calendar_ids . ")",
        $this->rc->db->now(),
        ($sql_set ? ', ' . join(', ', $sql_set) : '')
      ),
      $event['id']
    );

    $success = $this->rc->db->affected_rows($query);

    // add attachments
    if ($success && !empty($event['attachments'])) {
      foreach ($event['attachments'] as $attachment) {
        $this->add_attachment($attachment, $event['id']);
        unset($attachment);
      }
    }

    // remove attachments
    if ($success && !empty($event['deleted_attachments'])) {
      foreach ($event['deleted_attachments'] as $attachment) {
        $this->remove_attachment($attachment, $event['id']);
      }
    }

    if ($success) {
      unset($this->cache[$event['id']]);
      if ($update_recurring)
        $this->_update_recurring($event);
    }

    return $success;
    */
  }

  /** TODO: needed
   * Insert "fake" entries for recurring occurences of this event
   */
  private function _update_recurring($event)
  {
    /*
    if (empty($this->calendars))
      return;
    
    // clear existing recurrence copies
    $this->rc->db->query(
      "DELETE FROM " . $this->db_events . "
       WHERE recurrence_id=?
       AND calendar_id IN (" . $this->calendar_ids . ")",
       $event['id']
    );
    
    // create new fake entries
    if ($event['recurrence']) {
      // include library class
      require_once($this->cal->home . '/lib/calendar_recurrence.php');
      
      $recurrence = new calendar_recurrence($this->cal, $event);

      $count = 0;
      $duration = $event['start']->diff($event['end']);
      while ($next_start = $recurrence->next_start()) {
        $next_start->setTimezone($this->server_timezone);
        $next_end = clone $next_start;
        $next_end->add($duration);
        $notify_at = $this->_get_notification(array('alarms' => $event['alarms'], 'start' => $next_start, 'end' => $next_end));
        $query = $this->rc->db->query(sprintf(
          "INSERT INTO " . $this->db_events . "
           (calendar_id, recurrence_id, created, changed, uid, %s, %s, all_day, recurrence, title, description, location, categories, url, free_busy, priority, sensitivity, alarms, attendees, notifyat)
            SELECT calendar_id, ?, %s, %s, uid, ?, ?, all_day, recurrence, title, description, location, categories, url, free_busy, priority, sensitivity, alarms, attendees, ?
            FROM  " . $this->db_events . " WHERE event_id=? AND calendar_id IN (" . $this->calendar_ids . ")",
            $this->rc->db->quote_identifier('start'),
            $this->rc->db->quote_identifier('end'),
            $this->rc->db->now(),
            $this->rc->db->now()
          ),
          $event['id'],
          $next_start->format(self::DB_DATE_FORMAT),
          $next_end->format(self::DB_DATE_FORMAT),
          $notify_at,
          $event['id']
        );
        
        if (!$this->rc->db->affected_rows($query))
          break;
        
        // stop adding events for inifinite recurrence after 20 years
        if (++$count > 999 || (!$recurrence->recurEnd && !$recurrence->recurCount && $next_start->format('Y') > date('Y') + 20))
          break;
      }
    }
    */
  }

  /**
   * Read available calendars for the current user and store them internally
   */
  private function _read_calendars()
  {
    if (!empty($this->rc->user->ID)) {
      $calendar_ids = array();
      $this->calendars = array();

      $calendars = $this->caldav->FindCalendars();
      foreach ($calendars as $val)
      {
        $folder = array();
        $fpath = explode("/", $val->url, -1);
        if (is_array($fpath))
        {
          $id = array_pop($fpath);
          $this->calendars[$id] = array(
            'id'         => $id,
            'name'       => $val->displayname,
            'listname'   => $val->displayname,
            'color'      => self::DEFAULT_COLOR,
            'showalarms' => 0,
            'active'     => true,
            // 'class_name' => '',
            'readonly'   => false,
            'default'    => $id === 'default',
            'children'   => false,
          );

          // get internal data
          $result = $this->rc->db->query(
            'SELECT color, displayname FROM '.self::SQL_TABLE.' WHERE server_id=? and calendar_id=?',
            $this->caldav_url,
            $id
          );
          if ($result)
          {
            $row = $this->rc->db->fetch_assoc($result);
            if ($row)
            {
              $this->calendars[$id]['color'] = $row['color'];
              if ($row['displayname'])
              {
                $this->calendars[$id]['name'] = $row['displayname'];
                $this->calendars[$id]['listname'] = $row['displayname'];
              }
            }
            else
              $this->rc->db->query(
                'INSERT INTO '.self::SQL_TABLE.' VALUES (?,?,NULL,?)',
                $this->caldav_url,
                $id,
                self::DEFAULT_COLOR
              );
          }

          $calendar_ids[] = $id;
        }
      }

      $this->calendar_ids = join(',', $calendar_ids);
    }
  }

  /**
   * convert a vcard into an event array
   */
  private function vcard2event($vcard, $id, $calendar)
  {
    // initial and default values
    $event = array (
      'id'            => $id,
      'calendar'      => $calendar,
      'recurrence_id' => count($vcard->GetProperties("RECURRENCE-ID")) > 0 ? $id : NULL,
      'sensitivity'   => 'public',
      'readonly'      => true,
    );

    // iterate through the properties
    $properties = $vcard->GetProperties();
    foreach ($properties as $property)
    {
      switch ($property->Name())
      {
        case "UID":             
            $event['uid'] = $property->Value();
            break;

        case "DTSTART":
            $event['start'] = $this->_MakeUTCDate($property->Value(), $this->_ParseTimezone($property->GetParameterValue("TZID")));
            if (strlen($property->Value()) == 8)
              $event['allday'] = true;
            break;

        case "DTEND":
            $event['end'] = $this->_MakeUTCDate($property->Value(), $this->_ParseTimezone($property->GetParameterValue("TZID")));
            if (strlen($property->Value()) == 8)
              $event['allday'] = true;
            break;

        case "CREATED":
            $event['created'] = $this->_MakeUTCDate($property->Value());
            break;

        case "LAST-MODIFIED":
            $event['changed'] = $this->_MakeUTCDate($property->Value());
            break;

        case "SUMMARY":
            $event['title'] = $property->Value();
            break;

        case "LOCATION":
            $event['location'] = $property->Value();
            break;

        case "DESCRIPTION":
            $event['description'] = $property->Value();
            break;

        case "RRULE":
            $event['recurrence'] = $this->_ParseRRULE($property);
            break;

        case "EXDATE":
            if (isset($event['recurrence']))
              $event['recurrence'] = array();
            $event['recurrence']['EXDATE'] = array();
            $dates = explode(",", $property->Value());
            foreach ($dates as $date)
            {
              $event['recurrence']['EXDATE'][] = $this->_MakeUTCDate($date);
            }
            break;

        case "CATEGORIES":
            $event['categories'] = $property->Value();
            break;

        case "TRANSP":
            switch ($property->Value())
            {
              case "TRANSPARENT": $event['free_busy'] = "free"; break;
              case "OPAQUE":      $event['free_busy'] = "busy"; break;
            }
            break;

        case "PRIORITY":
            $event['priority'] = $property->Value();
            break;

        case "CLASS":
            switch ($property->Value())
            {
              case "PUBLIC":       $event['sensitivity'] = 'public'; break;
              case "PRIVATE":      $event['sensitivity'] = 'private'; break;
              case "CONFIDENTIAL": $event['sensitivity'] = 'confidential'; break;
            }
            break;
        
        case "STATUS":
            $event['status'] = $property->Value();
            break;
        
        // TODO: attachments, attendees
        default:
            rcube::write_log("caldav", "Unknown vCard field: " . $property->Name());
      }
    }

    return $event;
  }

  /** TODO: needed?
   * Save an attachment related to the given event
   */
  private function add_attachment($attachment, $event_id)
  {
    /*
    $data = $attachment['data'] ? $attachment['data'] : file_get_contents($attachment['path']);
    
    $query = $this->rc->db->query(
      "INSERT INTO " . $this->db_attachments .
      " (event_id, filename, mimetype, size, data)" .
      " VALUES (?, ?, ?, ?, ?)",
      $event_id,
      $attachment['name'],
      $attachment['mimetype'],
      strlen($data),
      base64_encode($data)
    );

    return $this->rc->db->affected_rows($query);
    */
  }

  /** TODO: needed?
   * Remove a specific attachment from the given event
   */
  private function remove_attachment($attachment_id, $event_id)
  {
    /*
    $query = $this->rc->db->query(
      "DELETE FROM " . $this->db_attachments .
      " WHERE attachment_id = ?" .
        " AND event_id IN (SELECT event_id FROM " . $this->db_events .
          " WHERE event_id = ?"  .
            " AND calendar_id IN (" . $this->calendar_ids . "))",
      $attachment_id,
      $event_id
    );

    return $this->rc->db->affected_rows($query);
    */
  }

  /**
   * Generate date object from string and timezone.
   * @param string $value
   * @param string $timezone
   */
  private function _MakeUTCDate($value, $timezone = null)
  {
    $tz = null;
    if ($timezone)
      $tz = timezone_open($timezone);
 
    //If there is no timezone set, we use the default timezone
    if (!$tz)
      $tz = timezone_open(date_default_timezone_get());
 
    //20110930T090000Z
    $date = date_create_from_format('Ymd\THis\Z', $value, timezone_open("UTC"));
    //20110930T090000
    if (!$date)
      $date = date_create_from_format('Ymd\THis', $value, $tz);
    //20110930 (Append T000000Z to the date, so it starts at midnight)
    if (!$date)
      $date = date_create_from_format('Ymd\THis\Z', $value . "T000000Z", $tz);
    
    return $date;
  }

  /**
   * Generate a tzid from various formats
   * @param str $timezone
   * @return timezone id
   */
  private function _ParseTimezone($timezone)
  {
    //(GMT+01.00) Amsterdam / Berlin / Bern / Rome / Stockholm / Vienna
    if (preg_match('/GMT(\\+|\\-)0(\d)/', $timezone, $matches))
      return "Etc/GMT" . $matches[1] . $matches[2];

    //(GMT+10.00) XXX / XXX / XXX / XXX
    if (preg_match('/GMT(\\+|\\-)1(\d)/', $timezone, $matches))
      return "Etc/GMT" . $matches[1] . "1" . $matches[2];
 
    //inverse.ca/20101018_1/Europe/Amsterdam or /inverse.ca/20101018_1/America/Argentina/Buenos_Aires
    if (preg_match('/\/[.[:word:]]+\/\w+\/(\w+)\/([\w\/]+)/', $timezone, $matches))
      return $matches[1] . "/" . $matches[2];

    return trim($timezone, '"');
  }
}
