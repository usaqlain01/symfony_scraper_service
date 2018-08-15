<?php

/*
 * A Console commands to fetch content from a url, gather links, then post to an API endpoint
 *
 * Sample:
 * php bin/console app:scrape http://usmanport.com/ [div that has the content in it] [link class] [limit of number of pages to parse]
 *
 */
namespace AppBundle\Command;

use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

use Goutte\Client;
use GuzzleHttp\Client as GuzzleClient;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\FileLoader;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;



class SymfonyScraperCommand extends Command implements ContainerAwareInterface
{

    private $container;
    private $site_urls = array();

    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('app:scrape')

            // the short description shown while running "php bin/console list"
            ->setDescription('Fetches a url.')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp("A command to fetch urls and scrape content...")

            ->addArgument('url', InputArgument::REQUIRED, 'The url to fetch.')
            ->addArgument('contentClass', InputArgument::REQUIRED, 'The class that contains main content on pages.')
            ->addArgument('articleNumber', InputArgument::REQUIRED, 'The max number of pages to crawl. 0 means all')


        ;

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $client = new Client();

        if (! isset($site_urls)) $site_urls = array();

        // TODO: Make this random from an array of browsers. Maybe ...
        $client->setHeader('User-Agent', "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.101 Safari/537.36");

        // These are the command line arguments
        $url = $input->getArgument('url');
        $contentClass = $input->getArgument('contentClass');
        $articleNumber = $input->getArgument('articleNumber');

        // Set up the crawler
        $crawler = $client->request('GET', $url);
        $this->links = array();

        // Get all links from the page
        $crawler->filter('a')->each(function ($node) {
            $link = $node->attr('href');

            if (isset($this->site_urls[$link])) {
                echo "Already got this one: " . $link .".\n";
                // don't add
            } else {
                print "New link: " . ($link) . "\n";
                $this->links[] = $link;
            }

        });

        // Now crawl the pages we just found
        $i = 0;
        foreach ($this->links as $link) {

            // check if the link it relative and also if it's offsite
            if (!preg_match("/^http/", $link)) {
                $link = $url . $link;
            } else {

                if (!preg_match("/^" . preg_replace("/\//", "\/", $url) .  "/", $link)) {
                    // Offsite link, skip
                    continue;

                }
            }

            // Crawl the link
            echo "\n\nLink to crawl next for content: " . $link . "\n";
            echo "We now have " . sizeof($this->site_urls) . " links\n";
            echo "......\n";

            $this->crawl($link, $contentClass);

            $i++;

            // Exit out if we're above our crawl threshold
            if (($articleNumber > 0) && ($i > $articleNumber)) continue;

        }

        // Finish and report
        echo "\n\n========================\n\n";
        echo "*** Crawl is done. Found " . sizeof($this->site_urls) . " links.";
        echo "\n\n========================\n\n";
        foreach ($this->site_urls as $url) {
            echo $url['url'] . "\t" . $url['title'] . "\n";
        }

    }

    function crawl($url, $contentClass) {

        $client = new Client();

        // Set up the crawler
        $crawler = $client->request('GET', $url);
        //$this->links = array();

        // Get all links from the page
        $crawler->filter('a')->each(function ($node) {
            $link = $node->attr('href');
            $this->links[] = $link;
            print "LINK: " . ($link) . "\n";
        });


        // debug - this is how you find groups of things on the page
        $crawler->filter('className')->each(function ($node) {
            echo "Content: ".$node->text()."\n";
        });

        try {
            $page_title = $crawler->filter('title')->text();
            echo "Page title: " . $page_title . "\n";
        } catch (\Exception $e) {
            $page_title = 'No page title';
            echo "Page title: " . $page_title . "\n";
        }


        echo "Content from that link:\n";
        // Group the content in classes as an array
        // contentClass could be just the name of a div, or maybe div > p if that's necessary
        $page_text = $crawler->filter('.' . $contentClass)->each(function ($node) {
            return $node->text();
        });

        // Maybe it needed to be a css id and not a class
        if (!$page_text) {
            $page_text = $crawler->filter('#' . $contentClass)->each(function ($node) {
                return $node->html();
            });
        }

        // Join it all back together
        $page_content = join($page_text, "\n");
        echo $page_content . "\n\n";

        if ($page_content) {
            $this->site_urls[$url]['status'] = 'crawled';
            $this->site_urls[$url]['title'] = $page_title;
            $this->site_urls[$url]['url'] = $url;
            echo "Added " . $url . "\n";
        }

        // TODO: Othe TBD fields, probably taxonomy, too
        $this->addToDrupal($page_title, $page_content, $url);

    }

    /*
     * A function that takes a title and content and creates a node in Drupal with the REST API
     */
    function addToDrupal($title, $content, $url) {

        $user = $this->container->getParameter('drupal_user');
        $pass = $this->container->getParameter('drupal_password');
        $drupal_environment = $this->container->getParameter('drupal_environment');

        // TOD add command line switch for dev/prod
        if ($drupal_environment == 'prod') {
            $drupl_api_url = $this->container->getParameter('prod_drupal_api');
        } else {
            $drupl_api_url = $this->container->getParameter('dev_drupal_api');
        }

        // Guzzle - a great curl wrapper
        $client = new GuzzleClient([
            'base_uri' => $drupl_api_url,
        ]);

        // Drupal needs a CSRF Token for POST
        // Gotcha: do not send auth when getting the token
        $res = $client->request('GET', '/rest/session/token');
        $csrf_token = $res->getBody();

        // Check to see if it worked
        if ($res->getStatusCode() == 200) {
            echo "CSRF Success: " . $csrf_token ."\n";
        } else {
            echo "Trouble getting the token.\n";
            exit();
        }

        // The field_url values were added to the content type (article) we are submitting to.
        // See documentation:README.md on how to to set up text format 'basic_html'.
        $serialized_entity = json_encode([
            '_links' => ['type' => [
                'href' => $drupl_api_url . '/rest/type/node/article'
            ]],
            'title' => [['value' => $title . " (" . $url . ")"]],
            'body' => [['value' => $content, 'format' => 'basic_html']],
            'field_url' => [['value' => $url]],
        ]);

        // Debug
        $content_client = new GuzzleClient([
            'base_uri' => $drupl_api_url,
        ]);

        try {
            // Send the JSON to the Drupal 8 API
            // Note: In past version (8.1.x and Older) the endpoint changed to '/entity/node' from '/entity/node?_format=hal_json'
            $res = $content_client->request('POST', '/entity/node', [
                'auth' => [$user, $pass],
                'body' => $serialized_entity,
                'headers' => [
                    'Content-Type' => 'application/hal+json',
                    'X-CSRF-Token' => $csrf_token
                ]
            ]);

            // Check to see if it worked
            if ($res->getStatusCode() == 201) {
                echo "POST Success: " . $res->getBody() . "\n";
            } else {
                echo "HTTP Error with POST: " . $res->getStatusCode() . "\n";
            }
        } catch (\Exception $e) {
            echo "=============================\n";
            echo 'Caught exception with POST: ',  $e->getMessage(), "\n";
            echo "Try clearing caches if you recently added or changed the content type: \ndrush cache-rebuild and/or /admin/config/development/performance Clear all Caches\n";

            // Helpful hints about errors
            switch ($res->getStatusCode()) {
                case 400:
                    echo "400 error likely means that the content type doesn't exist or you just created it and need to clear caches";
                    break;

                case 403:
                    echo "403 error means that your token was absent or incorrect and/or your username is incorrect or doesn't have permisssions\n";
                    break;
                case 500:
                    echo "500 error means that something happenend ont he server - check the logs\n";
            }

            echo "=============================\n";
        }

    }

    /*
     * A function to send content to GatherContent.com
     * TODO: Make it ...
     */

    protected function sendToGatherContent() {

        // https://docs.gathercontent.com/reference
        // https://coderwall.com/p/gtk3tw/symfony2-call-service-from-command
    }

    /**
     * Sets the container.
     *
     * @param ContainerInterface|null $container A ContainerInterface instance or null
     */
    public function setContainer(ContainerInterface $container = null)
    {
        // TODO: Implement setContainer() method.
        $this->container = $container;
    }

    public function get_site_urls(){
        return $this->site_urls;
    }
}