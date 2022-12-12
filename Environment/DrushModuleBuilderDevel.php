<?php

namespace DrupalCodeBuilderDrush\Environment;

use DrupalCodeBuilder\Environment\Drush;

/**
 * Drupal Code Builder environment class for development.
 *
 * This sets the storage to ExportInclude, which makes the stored data human-
 * readable.
 */
class DrushModuleBuilderDevel extends Drush {

  /**
   * The short class name of the storage helper to use.
   */
  protected $storageType = 'ExportInclude';

}
