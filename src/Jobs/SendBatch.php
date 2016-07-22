<?php
/**
 * This file is part of laravel-vk-requester package.
 *
 * @author ATehnix <atehnix@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace ATehnix\LaravelVkRequester\Jobs;

use ATehnix\LaravelVkRequester\Models\VkRequest;
use ATehnix\VkClient\Client;
use ATehnix\VkClient\Requests\ExecuteRequest;
use ATehnix\VkClient\Requests\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBatch implements ShouldQueue
{
    const DEFAULT_DELAY = 350;
    
    /**
     * Number of requests, nested in the "execute" request
     */
    const NUMBER_OF_REQUESTS = 5;

    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * @var string
     */
    protected $token;

    /**
     * @var Client
     */
    protected $api;

    /**
     * @var array
     */
    protected $requestIds;

    /**
     * @var Collection
     */
    protected $requests;

    /**
     * @var array
     */
    protected $responses;

    /**
     * Create a new job instance.
     *
     * @param Collection $requests
     * @param string $token Access token for Vk.com API
     */
    public function __construct(Collection $requests, $token)
    {
        $this->requests = $requests;
        $this->token = (string)$token;
        VkRequest::whereIn('id', $requests->pluck('id')->all())->delete();
    }

    /**
     * Execute the job.
     *
     * @param Client $api Pre-configured instance of the Client
     */
    public function handle(Client $api)
    {
        // Initialize
        $this->api = $api;
        $this->api->setDefaultToken($this->token);
        $this->api->setPassError(config('vk-requester.pass_error', true));

        // Processing
        if (!$this->requests->isEmpty()) {
            $this->sendToApi();
            $this->fireEvents();
        }
    }

    /**
     * Send requests to Vk.com API
     */
    protected function sendToApi()
    {
        usleep(config('vk-requester.delay', self::DEFAULT_DELAY) * 1000);
        $executeRequest = $this->makeExecuteRequest($this->requests);
        $executeResponse = $this->api->send($executeRequest);
        $this->responses = $this->getResponses($executeResponse);
    }

    /**
     * Make a new "execute" request instanse with nested requests.
     *
     * @param Collection $requests
     * @return ExecuteRequest
     */
    protected function makeExecuteRequest(Collection $requests)
    {
        $clientRequests = $requests->map(function (VkRequest $request) {
            return new Request($request->method, $request->parameters);
        });

        return ExecuteRequest::make($clientRequests->all());
    }

    /**
     * Get array of nested responses in "execute" response
     *
     * @param array $executeResponse
     * @return array
     */
    protected function getResponses(array $executeResponse)
    {
        if (isset($executeResponse['error'])) {
            return array_fill(0, $this->requests->count(), $executeResponse['error']);
        }

        $errors = isset($executeResponse['execute_errors']) ? $executeResponse['execute_errors'] : [];

        return array_map(function ($response) use (&$errors) {
            return $response ?: array_shift($errors);
        }, $executeResponse['response']);
    }

    /**
     * Fire an event for each of response
     */
    protected function fireEvents()
    {
        $requests = array_map(function (VkRequest $request, $response) {
            $status = isset($response['error_code']) ? VkRequest::STATUS_FAIL : VkRequest::STATUS_SUCCESS;
            $event = sprintf(VkRequest::EVENT_FORMAT, $status, $request->method, $request->tag);
            event($event, [$request, $response]);

            return $request;
        }, $this->requests->all(), $this->responses);

        $this->requests = collect($requests);
    }
}
