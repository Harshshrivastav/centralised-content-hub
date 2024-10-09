<?php

namespace Drupal\site_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\site_dashboard\Form\CreateSiteForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\admin_content_dashboard\Controller\ContentListController;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Exception\ConnectException;

/**
 * Class SiteDashboardController.
 */
class SiteDashboardController extends ControllerBase {

  /**
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;
  protected $configFactory;

  /**
   * Constructs a SiteDashboardController object.
   *
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder service.
   */
  public function __construct(FormBuilderInterface $formBuilder, ConfigFactoryInterface $configFactory) {
    $this->formBuilder = $formBuilder;
    $this->configFactory = $configFactory;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder'),
      $container->get('config.factory')
    );
  }

  public function dashboardPage() {
    // Get configuration settings for remote sites.
    $config = $this->config('config_sync.settings');
    $remote_sites = $config->get('remote_sites') ?? [];
  
    // Sort sites by newest first using timestamp (if available, else by name).
    usort($remote_sites, function($a, $b) {
      return ($b['created_time'] ?? 0) - ($a['created_time'] ?? 0); // Assumes 'created_time' holds the timestamp.
    });
  
    // Limit to 4 sites.
    $limited_sites = array_slice($remote_sites, 0, 4);
  
    // Start building the site list render array.
    $site_list = [
      '#theme' => 'item_list',
      '#items' => [],
      '#attributes' => ['class' => 'ccms-site-name-list'],
    ];
  
    // Check if there are any configured sites.
    if (empty($limited_sites)) {
      $site_list['#items'][] = [
        '#markup' => $this->t('No sites configured yet.'),
      ];
    } else {
      // Loop through limited sites and build the site listing.
      foreach ($limited_sites as $site_key => $site_data) {
        $site_name = $site_data['site_name'] ?? 'Unknown Site';
        $site_url = $site_data['url'] ?? '#';
  
        // Create the "Test Connection" link.
        $test_connection_url = Url::fromRoute('site_dashboard.test_connection', ['site_name' => str_replace('.', '_', $site_name)]);
        $test_connection_link = Link::fromTextAndUrl($this->t('Test Connection'), $test_connection_url)->toRenderable();
        $test_connection_link['#attributes'] = [
          'class' => ['button', 'btn-test-connection'],
          'data-site-name' => $site_name,
        ];
  
        // Structure each site as a list item.
        $site_list['#items'][] = [
          'dot' => [
            '#markup' => '<span class="dot" style="background-color:red; width: 10px; height: 10px; display: inline-block; border-radius: 50%;"></span>',
          ],
          'data' => [
            '#markup' => $this->t('<strong>@site_name</strong><br><a href="@url" target="_blank">@url</a>', [
              '@site_name' => str_replace('.com', '', $site_name),
              '@url' => $site_url,
            ]),
            '#allowed_tags' => ['strong', 'a', 'br'],
          ],
          'test_button' => $test_connection_link,
        ];
      }
    }
  
    // Create a "View All" link if there are more than 4 sites.
    $view_all_link = [];
    if (count($remote_sites) > 4) { // Show "View All" link if more than 4 sites exist.
      $view_all_url = Url::fromRoute('site_dashboard.view_all');
      $view_all_link = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['view-all-box']],
        'content' => [
          '#markup' => '
            <a href="' . $view_all_url->toString() . '" class="view-all-button">
              <div class="arrow">&rarr;</div>

            </a>',
        ],
      ];
    }
  
    // Create a container to hold the site list and the "View All" button.
    $container = [
      '#type' => 'container',
      '#attributes' => ['class' => ['site-dashboard-container']],
      'site_list' => $site_list,
      'view_all_link' => $view_all_link,
    ];
  
    $contentListController = new ContentListController($this->configFactory);
    $content_list = $contentListController->contentList();
  
    // Return a render array with the markup and the list.
    return [
      '#attached' => [
        'library' => [
          'site_dashboard/site_dashboard_styles',
        ],
      ],
      'dashboard_container' => $container,
      'content_list' => $content_list,
    ];
  }


/**
   * Displays the full list of sites.
   */
  public function viewAllSites() {
    // Get configuration settings for remote sites.
    $config = $this->config('config_sync.settings');
    $remote_sites = $config->get('remote_sites') ?? [];
  
    // Sort sites by newest first using timestamp (if available).
    usort($remote_sites, function($a, $b) {
      return ($b['created_time'] ?? 0) - ($a['created_time'] ?? 0);
    });
  
    // Start building the site list render array.
    $site_list = [
      '#theme' => 'item_list',
      '#items' => [],
      '#attributes' => ['class' => ['ccms-site-name-list', 'grid-four-columns']],
    ];
  
    if (empty($remote_sites)) {
      $site_list['#items'][] = [
        '#markup' => $this->t('No sites configured yet.'),
      ];
    } else {
      // Loop through all sites and build the site listing.
      foreach ($remote_sites as $site_key => $site_data) {
        $site_name = $site_data['site_name'] ?? 'Unknown Site';
        $site_url = $site_data['url'] ?? '#';
  
        // Create the "Test Connection" link.
        $test_connection_url = Url::fromRoute('site_dashboard.test_connection', ['site_name' => str_replace('.', '_', $site_name)]);
        $test_connection_link = Link::fromTextAndUrl($this->t('Test Connection'), $test_connection_url)->toRenderable();
        $test_connection_link['#attributes'] = [
          'class' => ['button', 'btn-test-connection'],
          'data-site-name' => $site_name,
        ];
  
        // Structure each site as a list item.
        $site_list['#items'][] = [
          'dot' => [
            '#markup' => '<span class="dot" style="background-color:red; width: 10px; height: 10px; display: inline-block; border-radius: 50%;"></span>',
          ],
          'data' => [
            '#markup' => $this->t('<strong>@site_name</strong><br><a href="@url" target="_blank">@url</a>', [
              '@site_name' => str_replace('.com', '', $site_name),
              '@url' => $site_url,
            ]),
            '#allowed_tags' => ['strong', 'a', 'br'],
          ],
          'test_button' => $test_connection_link,
        ];
      }
    }
  
    return [
      '#theme' => 'item_list',
      '#items' => $site_list['#items'],
      '#attributes' => ['class' => 'ccms-site-name-list'], // Consistent class usage
      '#attached' => [
        'library' => [
          'site_dashboard/view_all_styles', // Attach the CSS library
        ],
      ],
    ];
  }

  /**
   * Test the connection for a specific site.
   *
   * @param string $site_name
   *   The name of the site to test.
   */
  public function testConnection($site_name) {
    // Get configuration settings for remote sites
    $config = $this->config('config_sync.settings');
    $remote_sites = $config->get('remote_sites') ?? [];

    // Find the site configuration by matching the site_name
    $site_config = null;
    foreach ($remote_sites as $site_key => $site_data) {
        // Compare the site_name from the configuration with the provided site_name
        if ($site_data['site_name'] === str_replace('_', '.', $site_name)) {
            $site_config = $site_data;
            break;
        }
    }

    // If the site configuration is not found, show an error message
    if (!$site_config) {
        \Drupal::messenger()->addError($this->t('No configuration found for site: @site', ['@site' => $site_name]));
        // Redirect back to the site dashboard page
        $url = Url::fromRoute('site_dashboard.dashboard_page');
        return new RedirectResponse($url->toString());
    }

    // Use the site-specific URL, username, and password from the configuration
    $site_url = $site_config['url'];
    $username = $site_config['username'] ?? 'default_username'; // Use default or fetch from config
    $password = $site_config['password'] ?? 'default_password'; // Use default or fetch from config

    // Handle cases where username or password might be missing
    if (empty($username) || empty($password)) {
        \Drupal::messenger()->addError($this->t('Username or password is missing for site: @site', ['@site' => $site_name]));
        $url = Url::fromRoute('site_dashboard.dashboard_page');
        return new RedirectResponse($url->toString());
    }

    try {
        // Create a Guzzle HTTP client and send the POST request to the child site's REST endpoint
        $client = \Drupal::httpClient();
        $response = $client->request('POST', $site_url . '/rest-endpoint', [
            'auth' => [$username, $password],
            'json' => [
                'action' => 'test_connection',
            ],
        ]);

        // Check if the response is successful
        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getBody(), TRUE);
            \Drupal::messenger()->addStatus($this->t('Connection successful: @message (Status Code: @code)', [
                '@message' => $data['message'],
                '@code' => $response->getStatusCode(),
            ]));
        } else {
            \Drupal::messenger()->addError($this->t('Connection failed with status code: @code', [
                '@code' => $response->getStatusCode(),
            ])); 
        }
    } catch (ConnectException $e) {
      // Handle the connection-specific exception and display a warning in yellow.
      \Drupal::messenger()->addWarning($this->t('No site found or REST endpoint is not present at @site. Error: @error', [
        '@site' => $site_url,
        '@error' => $e->getMessage(),
      ]));
    } catch (\Exception $e) {
      // Catch any other exceptions and show a generic error message.
      \Drupal::messenger()->addError($this->t('Connection failed: @error', ['@error' => $e->getMessage()]));
    }

    // Redirect back to the dashboard page
    $url = Url::fromRoute('site_dashboard.dashboard_page');
    return new RedirectResponse($url->toString());
}
}
