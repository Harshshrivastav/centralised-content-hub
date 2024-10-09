<?php

namespace Drupal\child_test_endpoint\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


/**
 * Provides a Test Connection Resource.
 *
 * @RestResource(
 *   id = "test_connection_resource",
 *   label = @Translation("Test Connection Resource"),
 *   uri_paths = {
 *     "create" = "/rest-endpoint"
 *   }
 * )
 */
class TestConnectionResource extends ResourceBase {

  /**
   * Responds to POST requests.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *  A JSON response.
   */
  public function post(Request $request) {
    
    try {
      // Process request data if needed.
      // Return a success response indicating the child module is reachable.
      return new JsonResponse(['status' => 'success', 'message' => 'Test connection successful.'], Response::HTTP_OK);
      
    } catch (\Exception $e) {
      // Handle any exceptions that occur.
      return new JsonResponse(['error' => 'An error occurred: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }
}
