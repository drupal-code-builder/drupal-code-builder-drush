<?php

namespace Drupal\code_builder_drush;

// Include the parent class file, which the autoloader won't see.
include_once(dirname(__DIR__) . '/CodeBuilderCommands.php');

// Name should not end in 'Commands', so it's not picked up in non-module use.
class CodeBuilder extends \Drush\Commands\CodeBuilderCommands {

  // Dummy class.
  // Exist to be found by Drupal's module class autoloader, and then include our
  // own file.
  // @todo remove this when this project reverts to being just a global Drush
  // command.

}
