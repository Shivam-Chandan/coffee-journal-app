<?php

// This file is loaded by Drupal's settings.php. Use an IIFE to create a local
// scope and therefore avoid all side effects other than to modify $databases.
(function () use (&$databases) {
  $databases['default']['default'] ??= [];
  foreach (array_keys($databases) as $key) {
    foreach (array_keys($databases[$key]) as $target) {
      $databases[$key][$target]['driver'] ??= 'mysql';
      if ($databases[$key][$target]['driver'] === 'mysql') {
        $databases[$key][$target]['namespace'] = 'Drupal\\mysql57\\Driver\\Database\\mysql';

        // @todo Make this dynamic in case the module is in a different
        //   location.
        $databases[$key][$target]['autoload'] = 'modules/contrib/mysql57/src/Driver/Database/mysql/';

        $databases[$key][$target]['dependencies'] = [
          'mysql' => [
            'namespace' => 'Drupal\\mysql',
            'autoload' => 'core/modules/mysql/src/',
          ],
        ];
      }
    }
  }
})();
