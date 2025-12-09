CREATE TABLE `leads_07112025` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `clicked_at_date` DATE DEFAULT NULL,
  `user_id` BIGINT DEFAULT NULL,
  `first_name_cleaned` VARCHAR(100) DEFAULT NULL,
  `last_name_cleaned` VARCHAR(100) DEFAULT NULL,
  `phone_number` VARCHAR(32) NOT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `created_at_date` DATE DEFAULT NULL,
  `language` VARCHAR(10) DEFAULT NULL,
  `simplified_marketing_group` VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_phone` (`phone_number`(20)),
  KEY `idx_state` (`state`),
  KEY `idx_language` (`language`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;
