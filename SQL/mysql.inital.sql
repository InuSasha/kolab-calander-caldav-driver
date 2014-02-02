CREATE  TABLE `caldav_calendars` (
  `server_id` VARCHAR(255) NOT NULL,
  `calendar_id` VARCHAR(255) NOT NULL ,
  `color` VARCHAR(6) NULL DEFAULT 'cc0000' ,
  PRIMARY KEY (`calendar_id`, `server_id`) 
);
