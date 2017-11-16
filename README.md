This is a set of Drush commands for generating code with the Drupal Code Builder
library (https://github.com/drupal-code-builder/drupal-code-builder).

The 9.x branch works with Drush 9. For Drush 8, use the 8.x branch.

## Installation

There are two ways to install this command:

- Composer installation as a Drush extension
  1. Do `composer require drupal-code-builder/drupal-code-builder-drush` to
    install this package and its dependencies. You will need your project to
    use drupal-composer/drupal-project, or have your composer.josn configured
    to place Drush extensions into drush/contrib.
- Manual installation as Drush extension
  1. Place this somewhere Drush will locate it as a command, such as
    drush/contrib.
  2. Do `composer require drupal-code-builder/drupal-code-builder` to install
    the Drupal Coder Builder library.

Once this is installed, do `drush cbu`. This detects hooks, services, and plugin
types in your Drupal site's codebase and analyses them for use with Drupal Code
Builder.


## Usage

The following commands are available:

- `drush cb:list`: Lists all the hooks, services, and plugins that Drupal Code
  Builder has detected in your Drupal site's codebase.
- `drush cb:update`: Updates the stored definitions of Drupal hooks, services
  and plugin types.
- `drush cb:module`: Creates a module, or adds components to one.

## A note on history

Commits in this repository older than 2017-08-22 are extracted from other
repositories that originally were the home of this command file: the drupal.org
module_builder project, and a drush fork.
They were extracted using git filter-branch, and reconstituted into a single
history with git graft and git filter-branch.
