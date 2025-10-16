<?php

namespace Drupal\mysql57\Driver\Database\mysql\Install;

use Drupal\mysql\Driver\Database\mysql\Install\Tasks as CoreMysqlTasks;

class Tasks extends CoreMysqlTasks {
  const MYSQL_MINIMUM_VERSION = '5.7.8';
  const MARIADB_MINIMUM_VERSION = '10.3.7';

  public function name() {
    if (!$this->isConnectionActive()) {
      // For selecting the database driver during new site installation,
      // provide a different label than core's mysql driver.
      return $this->t('MySQL 5.7 or MariaDB 10.3, 10.4, or 10.5');
    }
    else {
      return parent::name();
    }
  }

}
