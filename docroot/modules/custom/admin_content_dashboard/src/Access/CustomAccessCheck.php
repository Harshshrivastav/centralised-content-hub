<?php

namespace Drupal\admin_content_dashboard\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountProxyInterface;

class CustomAccessCheck {

  protected $currentUser;

  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * Access check for administrator, site_admin, and reviewer roles.
   */
  public function accessAdminContent() {
    // Allow access for specific roles: administrator, site_admin, reviewer.
    if ($this->currentUser->hasRole('administrator') || $this->currentUser->hasRole('site_admin') || $this->currentUser->hasRole('reviewer')) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden();
  }

  /**
   * Access check for editor, site_admin, administrator, and reviewer roles.
   */
  public function accessEditorContent() {
    // Allow access for specific roles: editor, site_admin, administrator, reviewer.
    if ($this->currentUser->hasRole('editor') || $this->currentUser->hasRole('site_admin') || $this->currentUser->hasRole('administrator') || $this->currentUser->hasRole('reviewer')) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden();
  }
}
