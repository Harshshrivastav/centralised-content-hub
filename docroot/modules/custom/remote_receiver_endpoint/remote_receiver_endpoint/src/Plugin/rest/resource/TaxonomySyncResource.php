<?php

namespace Drupal\remote_receiver_endpoint\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\rest\ResourceResponse;

/**
 * Provides a resource to receive taxonomy data.
 *
 * @RestResource(
 *   id = "taxonomy_sync_resource",
 *   label = @Translation("Taxonomy Sync Resource"),
 *   uri_paths = {
 *     "create" = "/api/taxonomy-sync"
 *   }
 * )
 */
class TaxonomySyncResource extends ResourceBase {

  /**
   * Responds to POST requests to sync taxonomy terms and vocabularies.
   *
   * @param Request $request
   *   The incoming request containing taxonomy data.
   *
   * @return \Drupal\rest\ResourceResponse
   *   A ResourceResponse containing the result of the operation.
   */
  public function post(Request $request) {
    // Parse the incoming JSON data.
    $data = json_decode($request->getContent(), TRUE);

    $vocabularies = \Drupal\taxonomy\Entity\Vocabulary::loadMultiple();
    // If the vocabulary does not exist, create it.
    if (!isset($vocabularies[$data['vid']])) {
        $vocabulary = \Drupal\taxonomy\Entity\Vocabulary::create(array(
              'vid' => $data['vid'],
              'description' => '',
              'name' => $data['vocabulary'],
        ))->save();
    }

    // Check if a term with the same name exists in this vocabulary.
    $existing_terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'name' => $data['name'],
      'vid' => $data['vid'],
    ]);

    if (!empty($existing_terms)) {
      // If a term with the same name exists, return a message.
      $term = reset($existing_terms);  // Get the first matching term.
      return new ResourceResponse([
        'success' => TRUE,
        'remote_tid' => $term->id(),  // Return the term ID.
        'message' => 'Term already exists.',
      ], 200);
    } else {
      // Create a new term.
      $term = Term::create([
        'vid' => $data['vid'],  // Associate with the vocabulary.
        'name' => $data['name'],  // Use the incoming term name.
      ]);
      $term->save();

      $response_data = [
        'remote_tid' => $term->id(),
        'message' => 'term created successfully.'
      ];

      // Return the newly created term ID.
      return new ResourceResponse($response_data, 200);
    }
  }

  /**
   * Logs the exception to the logger service.
   *
   * @param \Exception $exception
   *   The exception that was thrown.
   */
  protected function logException(\Exception $exception) {
    $this->logger->error($exception->getMessage());
  }

}
