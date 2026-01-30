CREATE TABLE IF NOT EXISTS `PREFIX_erli_product_link` (
    `id_erli_product_link` INT AUTO_INCREMENT PRIMARY KEY,
    `id_product` INT NOT NULL,
    `id_product_attribute` INT DEFAULT NULL,
    `external_id` VARCHAR(64),
    `last_payload` MEDIUMTEXT NULL,
    `last_synced_at` DATETIME NULL,
    `last_error` TEXT NULL,
    INDEX (`id_product`),
    INDEX (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `PREFIX_erli_order_link` (
    `id_erli_order_link` INT AUTO_INCREMENT PRIMARY KEY,
    `id_order` INT NOT NULL,
    `erli_order_id` VARCHAR(64) NOT NULL,
    `last_status` VARCHAR(64) NULL,
    `created_at` DATETIME NULL,
    INDEX (`id_order`),
    INDEX (`erli_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `PREFIX_erli_log` (
    `id_erli_log` INT AUTO_INCREMENT PRIMARY KEY,
    `type` VARCHAR(32) NOT NULL,
    `reference_id` VARCHAR(64) NULL,
    `message` TEXT,
    `payload` MEDIUMTEXT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX (`type`),
    INDEX (`reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `PREFIX_erli_category_map` (
    `id_erli_category_map` INT AUTO_INCREMENT PRIMARY KEY,
    `id_category` INT NOT NULL,
    `erli_category_id` VARCHAR(64) NOT NULL,
    `erli_category_name` VARCHAR(255) NULL,
    UNIQUE KEY `uniq_erli_cat` (`id_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `PREFIX_erli_shipping_map` (
    `id_erli_shipping_map` INT AUTO_INCREMENT PRIMARY KEY,
    `id_carrier` INT NOT NULL,
    `erli_tag` VARCHAR(64) NOT NULL,
    `erli_name` VARCHAR(255) NULL,
    UNIQUE KEY `uniq_carrier` (`id_carrier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `PREFIX_erli_category_dictionary` (
  `id_erli_category_dictionary` INT AUTO_INCREMENT PRIMARY KEY,
  `erli_id` INT NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `leaf` TINYINT(1) NOT NULL DEFAULT 0,
  `breadcrumb_json` MEDIUMTEXT NULL,
  UNIQUE KEY `uniq_erli_id` (`erli_id`),
  INDEX `idx_leaf` (`leaf`),
  INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
