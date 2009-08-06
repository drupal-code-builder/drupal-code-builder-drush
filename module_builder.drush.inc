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
      '--build' => "Which file to generate: 'module', 'info', 'both'. Default is 'both' if writing new files, 'module' if appending to file or outputting only to terminal.",
      '--write' => 'Write files to sites/all/modules. Will prompt to overwrite existing files; use --yes to force. Use --quiet to suppress output to the terminal.',
      '--add' => 'Append hooks to module file. Implies --write --build=module. Warning: will not check hooks already exist.',
      '--name' => 'Redable name of the module.',
      '--desc' => 'Description (for the admin module list).',
      '--help' => 'Module help text (for the system help).',
      '--dep' => 'Dependencies, separated by spaces, eg "forum views".',
      '--package' => 'Module package.',
    ),
    'examples' => array(
      'drush mb my_module menu cron nodeapi' => 
        'Generate module code with hook_menu, hook_cron, hook_nodeapi.',
      'drush mb my_module --info --name="My module" --dep="forum views"' => 
        'Generate module info with readable name and dependencies.',
      'drush mb my_module menu cron --write --name="My module" --dep="forum views"' => 
        'Generate both module files, write files and also output to terminal.',
      'drush mb my_module menu cron --write ' => 
        'Generate module code, write files and also output to terminal.',
      'drush mb my_module menu cron --write --quiet --name="My module" --dep="forum views"' => 
        'Generate both module files, write files and output nothing to terminal.',
    ),
  );
  
  // TODO: add a download hook data command
  
  // TODO: add a hook description listing command
  
  $items['mbdebug'] = array(
    'callback' => 'module_builder_callback_debug',
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
  // Include generating component file.
  include(dirname(__FILE__) . '/../includes/generate.inc');
  
  $commands = func_get_args();
  
  // What to build
  $build = drush_get_option('build');
  
  // If we are adding, 'module' is implied
  if (drush_get_option('add')) {
    $build = 'module';
  }
  
  // The default is 'both' if we are writing, 'module' if outputting only to terminal.
  if (!$build) {
    if (drush_get_option('write')) {
      $build = 'both';
    }
    else {
      $build = 'module';      
    }
  }
  
  switch ($build) {
   case 'module':
      module_builder_callback_module($commands);
      return;  
    case 'info':
      module_builder_callback_info($commands);
      return;
    case 'both':
      module_builder_callback_module($commands);
      module_builder_callback_info($commands);
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
    
  $module_code = module_builder_generate_module($data, drush_get_option('add'));
  
  if (is_null($module_code)) {
    return drush_set_error('DRUSH_NOT_COMPLETED', 'No hook definitions found. You need to download hook definitions before using this module.');
  }
  
  module_builder_drush_output_code($data['module_root_name'], 'module', $module_code); 
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

  module_builder_drush_output_code($data['module_root_name'], 'info', $info_code); 
}

/**
 * Output generated text, to terminal or to file.
 */
function module_builder_drush_output_code($module_root_name, $type, $code) {
  
  $filename = $module_root_name . '.' . $type;
  
  // Output to terminal
  if (!drush_get_option('quiet')) {
    drush_print("Proposed $filename:");
    drush_print_r($code);
  }
  
  $write = drush_get_option('write');
    
  // Write to file
  // Add to file option implies write.
  if (drush_get_option(array('write', 'add'))) {
    
    $module_dir = pm_dl_destination('module');
    $module_dir .= $module_root_name;
    
    if (!is_dir($module_dir)) {
      @drush_op('mkdir', $module_dir, 0777);
      //drush_print("Module directory $module_dir created");
    }

    $filepath = $module_dir . '/' . $filename;
    
    // Add to file option
    // if file doesn't exist, we skip this and silently write it anyway
    if (drush_get_option('add') && file_exists($filepath)) {
      $fh = fopen($filepath, 'a');
      fwrite($fh, $code);
      fclose($fh);
      return;
    }

    // if file exists, ask for whether to overwrite
    if (file_exists($filepath)) {
      if (!drush_confirm(dt('File ' . $filename . ' exists. Do you really want to overwrite?'))) {
        return; 
      }
    }
    
    file_put_contents($filepath, $code);
  }
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

/**
 * Just for testing stuff on the commandline while developing the module.
 */
function module_builder_callback_debug() {
  include(dirname(__FILE__) . '/../includes/process.inc');
 
}