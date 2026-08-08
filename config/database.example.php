<?php

/**
 * PDO connection configuration.
 *
 * Copy to config/database.php and fill in the values for your environment.
 *
 * ----------------------------------------------------------------------------
 * InfinityFree
 * ----------------------------------------------------------------------------
 * Take the values from the client area, "MySQL Databases" section. They look
 * like this and are NEVER localhost:
 *
 *   host     sqlNNN.infinityfree.com     (the "MySQL Hostname")
 *   port     3306                        (not 8889: that is MAMP's port)
 *   database if0_NNNNNNNN_staffease      (always prefixed with if0_)
 *   username if0_NNNNNNNN                (same prefix, no suffix)
 *   password the one you chose when creating the database
 *
 * Leaving host at 127.0.0.1 is the single most common deployment mistake: the
 * database runs on a different machine than the web server.
 */
return [
    'driver' => 'mysql',
    'host' => 'sqlNNN.infinityfree.com',
    'port' => 3306,
    'database' => 'if0_NNNNNNNN_staffease',
    'username' => 'if0_NNNNNNNN',
    'password' => '',
    'charset' => 'utf8mb4',
];
