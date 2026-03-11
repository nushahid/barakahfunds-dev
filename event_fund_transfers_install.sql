CREATE TABLE IF NOT EXISTS `event_fund_transfers` (
  `ID` bigint(20) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `direction` varchar(30) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(500) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  KEY `idx_event_fund_transfers_event` (`event_id`),
  KEY `idx_event_fund_transfers_direction` (`direction`),
  KEY `idx_event_fund_transfers_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
