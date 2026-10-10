-- DEV only. Test credentials use the DEV application user, with a separate DB.
CREATE DATABASE IF NOT EXISTS smh_learning_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON smh_learning_test.* TO 'smh_dev'@'%';
