<?php
// $Id$

/**
 * @file
 *   Module builder drush commands.
 *
 */

/**
 * Implementation of hook_drush_command().
 *
 * In this hook, you specify which commands your
 * drush module makes available, what it does and 
 * description.
 *
 * Notice how this structure closely resembles how 
 * you define menu hooks.
 * 
 * @See drush_parse_command() for a list of recognized keys.
 *
 * @return
 *   An associative array describing your command(s).
 */
function module_builder_drush_command() {
  $items = array();

  // the key in the $items array is the name of the command.
  $items['mb'] = array(
    'callback' => 'module_builder_callback',
    'description' => "Generate the code for a new Drupal module, including file headers and hook implementations.",
    'arguments' => array(
      'module name' => 'The machine name of the module.',
      'hooks' => 'Short names of hooks, separated by spaces.',
    ),    
    'options' => array(
      '--info' => 'Generate the info file rather than the module.',
      '--name' => 'Redable name of the module.',
      '--desc' => 'Description (for the admin module list).',
      '--help' => 'Module help text (for the system help).',
      '--dep' => 'Dependencies, separated by spaces, eg "forum views".',
      '--package' => 'Module package.',
    ),
    'examples' => array(
      'drush mb my_module menu cron nodeapi' => 'Generate module code with hook_menu, hook_cron, hook_nodeapi.',
      'drush mb my_module --info --name="My module" --dep="forum views"' => 'Generate module info.',
    ),
  );
  return $items;
}

/**
 * Implementation of hook_drush_help().
 *
 * This function is called whenever a drush user calls
 * 'drush help <name-of-your-command>'
 *
 * @param
 *   A string with the help section (prepend with 'drush:')
 *
 * @return
 *   A string with the help text for your command.
 */
function module_builder_drush_help($section) {
  switch ($section) {
    case 'drush:mb':
      return dt("Generates module code with the specified hooks.");
  }
}

/**
 * Module builder drush command callback.
 *
 * Form:
 * $drush mb machine_name hookA hookB hookC
 * where 'hookA' is the short name, ie 'menu' not hook_menu'.
 */
function module_builder_callback() {
  $commands = func_get_args();
 
  // Swithc on whether the info file is requested or not.
  if (drush_get_option('info')) {
    module_builder_callback_info($commands);
    return;
  }
  else {
    module_builder_callback_module($commands);
    return;
  }
}

/**
 * Handles the callback for module code generation.
 */  
function module_builder_callback_module($commands) {
  // The first argument is the module machine name.
  $data['module_root_name'] = array_shift($commands);  
    
  foreach ($commands as $hook_name) {
    $data['hooks']["hook_$hook_name"] = TRUE;    
  }
    
  //drush_print_r($data);
  /*
  $input = drush_input('input?...');
  drush_print("You entered: >$input<");
  */
 
  // Heap of defaults. Later find some nice way of asking for these. 
  $data['module_readable_name'] = ucfirst($data['module_root_name']);
  $data['module_short_description'] = 'Description';
  
  $module_code = module_builder_generate_module($data);
  
  drush_print_r($module_code);
}

/**
 * Handles the callback for info code generation.
 */  
function module_builder_callback_info($commands) {
  // The first argument is the module machine name.
  $data['module_root_name'] = array_shift($commands);  
  
  $data['module_readable_name']     = drush_get_option('name');
  $data['module_short_description'] = drush_get_option('desc');
  $data['module_help_text']         = drush_get_option('help');
  $data['module_dependencies']      = drush_get_option('dep');
  $data['module_package']           = drush_get_option('package');

  $info_code = module_builder_generate_info($data);

  drush_print_r($info_code);
}

/**
 * Ask the user for input. DOESN'T WORK.
 *
 * @param $msg The question to ask
 * @return The entered string.
 */
function drush_input($msg, $required = FALSE, $indent = 0) {
  print str_repeat(' ', $indent) . (string)$msg . ": ";

  while ($line = trim(fgets(STDIN))) {
    if (!$required or strlen($line) > 0) {
      return $line;
    }
    print 'we never get here wtf?';
    print str_repeat(' ', $indent) . (string)$msg . ": ";
  }
}