USE vacation_brain;

-- Vacation Brain v1.34: booking/reservation management and trip readiness.
CREATE TABLE trip_bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  source_execution_id BIGINT UNSIGNED NULL,
  booking_type ENUM('flight','lodging','transport','event','restaurant','activity','document','other') NOT NULL DEFAULT 'other',
  title VARCHAR(180) NOT NULL,
  provider_name VARCHAR(180) NULL,
  confirmation_code VARCHAR(120) NULL,
  status ENUM('unbooked','ready_to_book','booked','confirmed','changed','cancelled') NOT NULL DEFAULT 'unbooked',
  payment_status ENUM('unknown','unpaid','deposit_paid','paid','refunded') NOT NULL DEFAULT 'unknown',
  amount DECIMAL(12,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  cancellation_deadline DATETIME NULL,
  checkin_opens_at DATETIME NULL,
  provider_url VARCHAR(1500) NULL,
  notes TEXT NULL,
  source ENUM('manual','agent_handoff','import') NOT NULL DEFAULT 'manual',
  requires_live_confirmation TINYINT(1) NOT NULL DEFAULT 0,
  last_verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_booking_execution (source_execution_id),
  KEY idx_trip_booking_trip (user_id,dream_trip_id,status,booking_type),
  KEY idx_trip_booking_deadline (user_id,cancellation_deadline,status),
  KEY idx_trip_booking_checkin (user_id,checkin_opens_at,status),
  CONSTRAINT fk_trip_booking_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_booking_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_booking_execution FOREIGN KEY (source_execution_id) REFERENCES trip_agent_action_executions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_readiness_requirements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  requirement_key VARCHAR(80) NOT NULL,
  requirement_type ENUM('flight','lodging','transport','event','restaurant','activity','document','other') NOT NULL DEFAULT 'other',
  title VARCHAR(180) NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('open','complete','not_needed') NOT NULL DEFAULT 'open',
  due_at DATETIME NULL,
  notes VARCHAR(700) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_readiness_requirement (user_id,dream_trip_id,requirement_key),
  KEY idx_trip_readiness_trip (user_id,dream_trip_id,is_required,status,sort_order),
  KEY idx_trip_readiness_due (user_id,due_at,status),
  CONSTRAINT fk_trip_readiness_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_readiness_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_booking_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('created','updated','status_changed','handoff_created','verified','deadline_changed') NOT NULL,
  detail_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_booking_event_booking (booking_id,id),
  KEY idx_trip_booking_event_trip (user_id,dream_trip_id,created_at),
  CONSTRAINT fk_trip_booking_event_booking FOREIGN KEY (booking_id) REFERENCES trip_bookings(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_booking_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_booking_event_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a practical checklist for existing trips. Optional requirements remain readiness-neutral
-- until the traveler marks them required.
INSERT IGNORE INTO trip_readiness_requirements (user_id,dream_trip_id,requirement_key,requirement_type,title,is_required,status,sort_order)
SELECT user_id,id,'flight','flight','Flights',IF(COALESCE(origin_iata,'')<>'' AND COALESCE(destination_iata,'')<>'',1,0),'open',10 FROM dream_trips WHERE status<>'abandoned';
INSERT IGNORE INTO trip_readiness_requirements (user_id,dream_trip_id,requirement_key,requirement_type,title,is_required,status,sort_order)
SELECT user_id,id,'lodging','lodging','Lodging',IF(start_date IS NOT NULL AND end_date IS NOT NULL AND DATEDIFF(end_date,start_date)>=1,1,0),'open',20 FROM dream_trips WHERE status<>'abandoned';
INSERT IGNORE INTO trip_readiness_requirements (user_id,dream_trip_id,requirement_key,requirement_type,title,is_required,status,sort_order)
SELECT user_id,id,'transport','transport','Local transportation',0,'open',30 FROM dream_trips WHERE status<>'abandoned';
INSERT IGNORE INTO trip_readiness_requirements (user_id,dream_trip_id,requirement_key,requirement_type,title,is_required,status,sort_order)
SELECT user_id,id,'events','event','Events & tickets',0,'open',40 FROM dream_trips WHERE status<>'abandoned';
INSERT IGNORE INTO trip_readiness_requirements (user_id,dream_trip_id,requirement_key,requirement_type,title,is_required,status,sort_order)
SELECT user_id,id,'restaurants','restaurant','Restaurant reservations',0,'open',50 FROM dream_trips WHERE status<>'abandoned';
INSERT IGNORE INTO trip_readiness_requirements (user_id,dream_trip_id,requirement_key,requirement_type,title,is_required,status,sort_order)
SELECT user_id,id,'activities','activity','Activities',0,'open',60 FROM dream_trips WHERE status<>'abandoned';
INSERT IGNORE INTO trip_readiness_requirements (user_id,dream_trip_id,requirement_key,requirement_type,title,is_required,status,sort_order)
SELECT user_id,id,'documents','document','Documents & reminders',0,'open',70 FROM dream_trips WHERE status<>'abandoned';

-- Convert previously approved booking handoffs into explicit Ready to Book records.
INSERT IGNORE INTO trip_bookings (
  user_id,dream_trip_id,source_execution_id,booking_type,title,status,payment_status,currency,notes,source,requires_live_confirmation,created_at,updated_at
)
SELECT
  e.user_id,e.dream_trip_id,e.id,
  CASE JSON_UNQUOTE(JSON_EXTRACT(e.proposal_json,'$.item_type'))
    WHEN 'flight' THEN 'flight'
    WHEN 'hotel' THEN 'lodging'
    WHEN 'food' THEN 'restaurant'
    WHEN 'activity' THEN 'activity'
    WHEN 'experience' THEN 'activity'
    ELSE 'other'
  END,
  COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.proposal_json,'$.title')),''),a.title),
  'ready_to_book','unknown',COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.proposal_json,'$.currency')),''),'USD'),
  CONCAT('Agent booking handoff. ',COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.proposal_json,'$.approval_note')),''),'Confirm live availability, final price, terms, and payment with the provider.')),
  'agent_handoff',1,e.updated_at,e.updated_at
FROM trip_agent_action_executions e
JOIN trip_agent_actions a ON a.id=e.action_id
WHERE e.proposal_type='booking_handoff' AND e.status='completed';

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.34')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
