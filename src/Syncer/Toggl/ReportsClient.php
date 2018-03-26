<?php declare(strict_types=1);

namespace Syncer\Toggl;

use Carbon\Carbon;
use GuzzleHttp\Client;
use JMS\Serializer\SerializerInterface;
use Syncer\Dto\Toggl\DetailedReport;

/**
 * Class ReportsClient
 *
 * @package Syncer\Toggl
 *
 * @author  Matthieu Calie <matthieu@calie.be>
 */
class ReportsClient {
    const VERSION = 'v2';

    /**
     * @var Client;
     */
    private $client;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var string
     */
    private $api_key;

    /**
     * TogglClient constructor.
     *
     * @param Client              $client
     * @param SerializerInterface $serializer
     * @param                     $api_key
     */
    public function __construct(Client $client, SerializerInterface $serializer, $api_key) {
        $this->client     = $client;
        $this->serializer = $serializer;
        $this->api_key    = $api_key;
    }

    /**
     * Get detailed report from since yesterday
     *
     * @param int         $workspaceId
     * @param Carbon|null $since
     * @param Carbon|null $until
     *
     * @return array|object|DetailedReport
     */
    public function getDetailedReport(int $workspaceId, Carbon $since = null, Carbon $until = null) {
        if ($since === null) {
            $since = Carbon::yesterday();
        }

        if ($until === null) {
            $until = Carbon::today();
        }

        $res = $this->client->request('GET', self::VERSION . '/details', [
            'auth'  => [$this->api_key, 'api_token'],
            'query' => [
                'user_agent'   => 'matthieu@calie.be',
                'workspace_id' => $workspaceId,
                'since'        => $since->format('Y-m-d'),
                'until'        => $until->format('Y-m-d'),
            ],
        ]);

        return $this->serializer->deserialize($res->getBody(), DetailedReport::class, 'json');
    }
}
