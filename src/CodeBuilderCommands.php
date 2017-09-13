<?php

namespace Drupal\code_builder_drush;

// Include the parent class file, which the autoloader won't see.
include_once(dirname(__DIR__) . '/CodeBuilderCommands.php');

class CodeBuilderCommands extends \Drush\Commands\CodeBuilder\CodeBuilderCommands {

  // Dummy class.
  // Exist to be found by Drupal's module class autoloader, and then include our
  // own file.
  // @todo remove this when this project reverts to being just a global Drush
  // command.

}
