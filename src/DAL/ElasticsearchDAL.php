<?php

namespace Isswp101\Persimmon\DAL;

use Elasticsearch\Client;
use Isswp101\Persimmon\Collection\ElasticsearchCollection;
use Isswp101\Persimmon\ElasticsearchModel;

class ElasticsearchDAL implements IDAL
{
    protected $model;
    protected $client;

    public function __construct(ElasticsearchModel $model, Client $client = null)
    {
        $this->model = $model;
        $this->client = $client;
    }

    public function getModel()
    {
        return $this->model;
    }

    public function get($id, array $options = [])
    {
        $params = $this->model->getPath()->toArray();

        if (!empty($options['columns'])) {
            $params['_source'] = $options['columns'];
        }

        if (!empty($options['parent'])) {
            $params['parent'] = $options['parent'];
        }

        $response = $this->client->get($params);

        return $this->model->fillByResponse($response);
    }

    public function put(array $columns = ['*'])
    {
        $params = $this->getParams();

        if (!$this->model->_exist || $columns == ['*']) {
            if (!$params['id']) {
                unset($params['id']);
            }

            $params['body'] = $this->model->toArray();

            $response = $this->client->index($params);
        } else {
            $params['body'] = [
                'doc' => array_only($this->model->toArray(), $columns)
            ];

            $response = $this->client->update($params);
        }

        $this->model->setId($response['_id']);

        return $this->model->getId();
    }

    public function delete()
    {
        return $this->client->delete($this->getParams());
    }

    protected function getParams()
    {
        $params = $this->model->getPath()->toArray();

        if ($this->model->getParentId()) {
            $params['parent'] = $this->model->getParentId();
        }

        return $params;
    }

    public function search(array $query)
    {
        if (empty($query['body']['query']) && empty($query['body']['filter'])) {
            $query['body']['query'] = [
                'match_all' => []
            ];
        }

        $params = [
            'index' => $this->model->getIndex(),
            'type' => $this->model->getType(),
            'from' => array_get($query, 'from', 0),
            'size' => array_get($query, 'size', 50),
            'body' => $query['body']
        ];

        // @TODO: use own logger, not model logger
        if ($this->model->hasLogger()) {
            $this->model->getLogger()->debug('Query', $params);
        }

        $collection = new ElasticsearchCollection();

        $response = $this->client->search($params);

        $collection->response($response);

        $from = (int)$params['from'];
        foreach ($response['hits']['hits'] as $hit) {
            $model = $this->model->createInstance();
            $model->_score = $hit['_score'];
            $model->_position = $from++;
            $model->_exist = true;
            $model->fillByResponse($hit);
            $model->fillByInnerHits($hit);
            $collection->put($model->getId(), $model);
        }

        return $collection;
    }
}
