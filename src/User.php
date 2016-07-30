<?php

class User
{
    const STAGE_INIT = 1;
    const STAGE_SEARCH = 2;
    const CHOOSE_FIRST_LANGUAGE = 3;
    const WAIT_FOR_ACTION = 4;
    const STAGE_CHAT = 5;
    const STAGE_WAIT_CHAT = 6;
    protected $columns = [
        'id' => 'integer',
        'chatId' => 'integer',
        'sex' => 'integer',
        'stage' => 'integer',
    ];

    
    public function getKind() {
        return 'VkUser';
    }
    
    public function __construct($datastoreDatasetId)
    {
        $this->datasetId = $datastoreDatasetId;
        $retryConfig = ['retries' => 2];
        $client = new \Google_Client(['retry' => $retryConfig]);
        $client->setScopes([
            \Google_Service_Datastore::CLOUD_PLATFORM,
            \Google_Service_Datastore::DATASTORE,
        ]);
        $client->useApplicationDefaultCredentials();
        $this->datastore = new \Google_Service_Datastore($client);
    }

    protected static $instance = null;

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new User('chattochatbot');
        }
        return self::$instance;
    }

    public function create($user, $key = null)
    {
        $this->verifyUser($user);

        if (is_null($key)) {
            $key = $this->createKey($user['id']);
        }

        $properties = $this->userToProperties($user);

        $entity = new \Google_Service_Datastore_Entity([
            'key' => $key,
            'properties' => $properties
        ]);

        // Use "NON_TRANSACTIONAL" for simplicity (as we're only making one call)
        $request = new \Google_Service_Datastore_CommitRequest([
            'mode' => 'NON_TRANSACTIONAL',
            'mutations' => [
                [
                    'upsert' => $entity,
                ]
            ]
        ]);

        $response = $this->datastore->projects->commit($this->datasetId, $request);

        $key = $response->getMutationResults()[0]->getKey();


        // return the ID of the created datastore item
        return $user['id'];
    }

    public function getFreeUser($sex, $id, $twice = false)
    {
        if ($sex == 1) {
            $sexFind = 2;
        } else {
            $sexFind = 1;
        }
        $filters = [
            [
                'propertyFilter' => [
                    'property' => [
                        'name' => 'stage'
                    ],
                    'op' => 'EQUAL',
                    'value' => [
                        'integerValue' => self::STAGE_WAIT_CHAT
                    ]
                ]
            ],
            [
                'propertyFilter' => [
                    'property' => [
                        'name' => 'id'
                    ],
                    'op' => $twice ? 'LESS_THAN' : 'GREATER_THAN',
                    'value' => [
                        'integerValue' => $id
                    ]
                ]
            ]
        ];
        if (USE_SEX) {
            $filters[] = [
                'propertyFilter' => [
                    'property' => [
                        'name' => 'sex'
                    ],
                    'op' => 'EQUAL',
                    'value' => [
                        'integerValue' => (int)$sexFind
                    ]
                ]
            ];
        }
        $query = new \Google_Service_Datastore_Query([
            'kind' => [
                [
                    'name' => $this->getKind(),
                ],
            ],
            'order' => [
                'property' => [
                    'name' => 'id',
                ],
            ],
            "filter" => [
                'compositeFilter' => [
                    'op' => 'AND',
                    'filters' => $filters
                ]
            ],
            'limit' => 1,
            'startCursor' => null,
        ]);
        $request = new \Google_Service_Datastore_RunQueryRequest();
        $request->setQuery($query);
        $response = $this->datastore->projects->
        runQuery($this->datasetId, $request);
        /** @var \Google_Service_Datastore_QueryResultBatch $batch */
        $batch = $response->getBatch();
        $users = [];
        foreach ($batch->getEntityResults() as $entityResult) {
            $entity = $entityResult->getEntity();
            $user = $this->propertiesToUser($entity->getProperties());
            $users[] = $user;
        }
        if (!empty($users)) {
            return $users[0];
        } else {
            if (!$twice) {
                return $this->getFreeUser($sex, $id, true);
            } else {
                return false;
            }
        }
    }

    public function read($id)
    {
        $key = $this->createKey($id);
        $request = new \Google_Service_Datastore_LookupRequest([
            'keys' => [$key]
        ]);

        $response = $this->datastore->projects->
        lookup($this->datasetId, $request);

        /** @var \Google_Service_Datastore_QueryResultBatch $batch */
        if ($found = $response->getFound()) {
            $user = $this->propertiesToUser($found[0]['entity']['properties']);
            $user['id'] = $id;

            return $user;
        }

        return false;
    }

    public function update($user)
    {
        $this->verifyUser($user);

        if (!isset($user['id'])) {
            throw new InvalidArgumentException('User must have an "id" attribute');
        }

        $key = $this->createKey($user['id']);
        $properties = $this->userToProperties($user);

        $entity = new \Google_Service_Datastore_Entity([
            'key' => $key,
            'properties' => $properties
        ]);

        // Use "NON_TRANSACTIONAL" for simplicity (as we're only making one call)
        $request = new \Google_Service_Datastore_CommitRequest([
            'mode' => 'NON_TRANSACTIONAL',
            'mutations' => [
                [
                    'update' => $entity
                ]
            ]
        ]);

        $response = $this->datastore->projects->commit($this->datasetId, $request);

        // return the number of updated rows
        return 1;
    }

    public function delete($id)
    {
        $key = $this->createKey($id);

        // Use "NON_TRANSACTIONAL" for simplicity (as we're only making one call)
        $request = new \Google_Service_Datastore_CommitRequest([
            'mode' => 'NON_TRANSACTIONAL',
            'mutations' => [
                [
                    'delete' => $key
                ]
            ]
        ]);

        $response = $this->datastore->projects->commit($this->datasetId, $request);

        return true;
    }

    protected function createKey($id = null)
    {
        $key = new \Google_Service_Datastore_Key([
            'path' => [
                [
                    'kind' => $this->getKind()
                ],
            ]
        ]);

        // If we have an ID, set it in the path
        if ($id) {
            $key->getPath()[0]->setId($id);
        }

        return $key;
    }

    private function verifyUser($user)
    {
        if ($invalid = array_diff_key($user, $this->columns)) {
            throw new \InvalidArgumentException(sprintf(
                'unsupported user properties: "%s"',
                implode(', ', $invalid)
            ));
        }
    }

    private function userToProperties(array $user)
    {
        $properties = [];
        foreach ($user as $colName => $colValue) {
            $propName = $this->columns[$colName] . 'Value';
            if (!empty($colValue)) {
                $properties[$colName] = [
                    $propName => $colValue
                ];
            }
        }

        return $properties;
    }

    private function propertiesToUser(array $properties)
    {
        $user = [];
        foreach ($this->columns as $colName => $colType) {
            $user[$colName] = null;
            if (isset($properties[$colName])) {
                $propName = $colType . 'Value';
                $user[$colName] = $properties[$colName][$propName];
            }
        }

        return $user;
    }

    public function findOrCreate($chat_id, $sex)
    {
        $user = $this->read($chat_id);
        if ($user && !empty($user)) {
            return $user;
        } else {
            $this->create([
                'id' => $chat_id,
                'chatId' => 0,
                'sex' => (int)$sex,
                'stage' => self::STAGE_INIT,
            ]);
            return $this->read($chat_id);
        }
    }
}