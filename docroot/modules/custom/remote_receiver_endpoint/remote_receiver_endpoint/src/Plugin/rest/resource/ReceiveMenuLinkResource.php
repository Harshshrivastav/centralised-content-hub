<?php

namespace Drupal\remote_receiver_endpoint\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\system\Entity\Menu;  // Correct namespace for Menu entity
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Provides a REST resource for receiving menu links.
 *
 * @RestResource(
 *   id = "receive_menu_link_resource",
 *   label = @Translation("Menu Link Receive Resource"),
 *   uri_paths = {
 *     "create" = "/api/receive-menu-link"
 *   }
 * )
 */
class ReceiveMenuLinkResource extends ResourceBase {

  /**
   * Receives a POST request to create a menu link.
   *
   * @param Request $request
   *   The incoming request object.
   *
   * @return \Drupal\rest\ResourceResponse
   *   A response containing the result of the operation.
   */
  public function post(Request $request) {
    // Decode the incoming JSON data.
    $data = json_decode($request->getContent(), TRUE);
    // Validate the required fields.
    if (!empty($data['menu_name']) && !empty($data['title']) && !empty($data['link__uri'])) {
      // Prepare the data for the new menu link.

      // $menu_id = $data['menu_name'];
      // $menu = Menu::load($menu_id);  // Correct syntax
      // if (!$menu) {
      //   // Create the menu.
      //   $menu = Menu::create([
      //     'id' => $menu_id,
      //     'label' => 'Custom Menu',
      //     'description' => 'A menu created programmatically.',
      //   ]);
      //   $menu->save();
      // }
      $values = [
        'menu_name' => $data['menu_name'],
        'link' => !empty($data['link__uri']) ? $data['link__uri'] : '/',
        'title' => $data['title'],
      ];

      // Create a new menu link.
      $menu_link = MenuLinkContent::create($values);
      $menu_link->save();

      // Prepare the response with the new menu link ID.
      $response_data = [
        'remote_menu_link_id' => $menu_link->id(),
        'message' => 'Menu link created successfully.'
      ];
      

      return new ResourceResponse($response_data, 200);
    }
    else {
      // If data is missing, return an error.
      throw new UnprocessableEntityHttpException('Required fields: menu_name, title, link__uri');
    }
  }
}
