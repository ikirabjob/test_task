<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;
use Symfony\Component\DomCrawler\Crawler;

class Amazon extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amazon:parse';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawl Amazon and search for a set or given keywords';

    /**
     * @var string[]
     */
    protected $keywords = [
        'Ice Cream Scoop',
        'Insulated Tumbler',
        'First Aid Kit',
        'Fire Starter',
        'Dry Erase Markers',
        'Digital Pianos',
        'Digital Cameras',
        'DJ Headphones',
        'Compression Socks',
        'Ceiling fan',
        'Camping Lantern',
        'Bluetooth Speaker'
    ];

    /**
     * @var
     */
    protected $file;

    /**
     * @var string
     */
    protected $searchUrl = 'https://www.amazon.com/s';

    /**
     * @var string
     */
    protected $cookiesUrl = 'https://www.amazon.com';

    /**
     * Execute the console command.
     */
    public function handle() : void
    {
        $cookies = $this->getCookies();
        $client = new Client([
            'cookies' => $cookies,
            'headers' => [
                'User-Agent'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.81 Safari/537.36'
            ]
        ]);

        $requests = function ($keywords) {
            foreach ($keywords as $s) {
                $uri = $this->searchUrl.'?'.http_build_query(['k' => $s]);
                yield new Request('GET', $uri);
            }
        };

        $this->createCsvColumns();

        $pool = new Pool($client, $requests($this->keywords), [
            'concurrency' => 6,
            'fulfilled' => function (Response $response, $index) {
                $result = $this->parseResponse($response, $index);
                $this->createCsv($this->keywords[$index], $result);
            },
            'rejected' => function (RequestException $reason, $index) {
                $this->info('Parse url '.$reason->getRequest()->getUri(). ' is response a '.$reason->getResponse()->getStatusCode());
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();
        $this->closeCsv();
    }

    protected function createCsvColumns(): void
    {
        $fileName = 'KEYWORDWINNER_'.Carbon::now()->format('Y_m_d');
        $columns = array('Keyword', 'Publisher', 'Article name', 'Publish date', 'Article URL', 'Scraping date', 'no_recommendation');
        $this->file = fopen(public_path($fileName.'.csv'), 'w+');
        fputcsv($this->file, $columns);
    }

    protected function createCsv($keyword, $result): void
    {
        if (is_resource($this->file)) {
            if ($result !== null) {
                fputcsv($this->file, array_merge([$keyword], array_values($result), [Carbon::now()->toDateString(), 0]));
            } else {
                $result = array_merge([$keyword], ['', '', '', ''], [Carbon::now()->toDateString(), 1]);
                fputcsv($this->file, $result);
            }
        }
    }

    protected function closeCsv(): void
    {
        fclose($this->file);
    }

    protected function getCookies(): CookieJar
    {
        try {
            $jar = new CookieJar();
            $client = new Client(['cookies' => true]);
            $client->get($this->cookiesUrl, [
                'cookies' => $jar,
                'headers'=>[
                    'User-Agent'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.81 Safari/537.36'
                ]
            ]);
        } catch (\Exception $exception) {
            return $this->getCookies();
        }

        return $jar;
    }

    protected function parseResponse(Response $response, $index): ?array
    {
        try {
            if ($response->getStatusCode() === 200) {
                $body = $response->getBody();
                $crawler = new Crawler((string)$body);
                $publisher = $crawler->filter('div.a-fixed-left-grid-inner')->each(function ($node){
                    return (string)$node->filter('a.a-link-normal')->text();
                });
                $result['publisher'] = array_shift($publisher);
                $result['article_name'] = $crawler->filter('h5.a-text-normal > span')->text();
                $result['publish_date'] = $crawler->filter('div.a-row.a-spacing-small > span.a-color-secondary')->text();
                $result['article_url'] = $crawler->filter('div.a-spacing-medium > a.a-link-normal')->attr('href');

                return $result;
            }
        } catch (\Exception $exception) {
            return null;
        }

        return null;
    }
}
