This is a set of Drush commands (for Drush 9.x) for generating code with the
Drupal Code Builder library
(https://github.com/drupal-code-builder/drupal-code-builder).

## Installation

Due to bugs in Drush 9.x, installing this is not straightforward. There are
three methods, in order of increasing complexity:

1. Hack it as a Drupal module
2. Manual installation as a Drush extension
3. Composer installation as a Drush extension

For details on each method, see below.

Once this is installed, do `drush cbu`. This detects hooks, services, and plugin
types in your Drupal site's codebase and analyses them for use with Drupal Code
Builder.

### Drupal module installation

1. Place this somewhere Drupal will find it as a module, e.g. modules/custom.
2. Enable the 'code_buider_drush' module.
3. Do `composer require drupal-code-builder/drupal-code-builder` to install the
   Drupal Coder Builder library.

Note that once this works properly as a Drush extension, a future version will
remove the files that declare this as a Drupal module.

### Manual installation as Drush extension

1. Place this somewhere Drush will locate it as a command.
2. Do `composer require drupal-code-builder/drupal-code-builder` to install the
   Drupal Coder Builder library.
3. Command discovery expects the namespace of the class to match the filepath.
   Rename this package's folder to 'CodeBuilder' to work around this.

### Composer installation as a Drush extension

1. Do `composer require drupal-code-builder/drupal-code-builder-drush` to
   install this package and its dependencies.

The following Drush bugs affect command discovery:

- https://github.com/drush-ops/drush/issues/2918: Command files aren't getting
  searched deeply enough in /drush. As a workaround, the PHP class file can be
  hacked into DrupalBoot::commandfileSearchpaths by adding to
  the returned array:
  ```
  $commandFiles[FILEPATH] = "\Drush\Commands\CodeBuilder\CodeBuilderCommands";
  ```
- https://github.com/drush-ops/drush/issues/2919: Once the command file is
  registered, this bug causes Drush to crash. As a workaround, change the method
  DrushCommands::printFile() to protected.


## Usage

The following commands are available:

- `drush cb-list`: Lists all the hooks, services, and plugins that Drupal Code
  Builder has detected in your Drupal site's codebase.
- `drush cb-update`: Updates the stored definitions of Drupal hooks, services
  and plugin types.
- `drush cb-module`: Creates a module, or adds components to one.

## A note on history

Commits in this repository older than 2017-08-22 are extracted from other
repositories that originally were the home of this command file: the drupal.org
module_builder project, and a drush fork.
They were extracted using git filter-branch, and reconstituted into a single
history with git graft and git filter-branch.
