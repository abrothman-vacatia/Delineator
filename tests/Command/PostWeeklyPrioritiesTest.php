<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PostWeeklyPriorities;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PostWeeklyPrioritiesTest extends TestCase
{
    private CommandTester $commandTester;
    private MockHandler $mockHandler;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up environment variables for testing
        putenv('LINEAR_API_KEY=test_linear_key');
        putenv('SLACK_OAUTH_TOKEN=xoxb-test-token');
        putenv('SLACK_CHANNEL_ID=C1234567890');
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up environment variables
        putenv('LINEAR_API_KEY');
        putenv('SLACK_OAUTH_TOKEN');
        putenv('SLACK_CHANNEL_ID');
    }
    
    private function createCommandWithMockClient(array $responses): CommandTester
    {
        $this->mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($this->mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        
        // Create command with mocked HTTP client
        $command = new class($mockClient) extends PostWeeklyPriorities {
            private Client $testClient;
            
            public function __construct(Client $client)
            {
                parent::__construct();
                $this->testClient = $client;
            }
            
            protected function execute($input, $output): int
            {
                // Override to inject our mock client
                $reflection = new \ReflectionClass(PostWeeklyPriorities::class);
                $property = $reflection->getProperty('httpClient');
                $property->setAccessible(true);
                
                $result = parent::execute($input, $output);
                
                // Replace the client after parent creates it
                $property->setValue($this, $this->testClient);
                
                // Run the actual execution with our mock
                return parent::execute($input, $output);
            }
        };
        
        $application = new Application();
        $application->add($command);
        
        return new CommandTester($command);
    }
    
    public function testExecuteWithDryRunShowsPreview(): void
    {
        // Given: Mock Linear API response with sample issues
        $linearResponse = [
            'data' => [
                'viewer' => [
                    'id' => 'test-user-id',
                    'name' => 'Test User',
                    'assignedIssues' => [
                        'nodes' => [
                            [
                                'id' => 'issue-1',
                                'identifier' => 'TEST-1',
                                'title' => 'Test Issue 1',
                                'url' => 'https://linear.app/test/issue/TEST-1',
                                'estimate' => 3,
                                'priority' => 1,
                                'createdAt' => '2025-11-01T10:00:00Z',
                                'completedAt' => null,
                                'cycle' => [
                                    'startsAt' => '2025-11-04T00:00:00Z',
                                    'isActive' => true,
                                    'isNext' => false,
                                    'isPrevious' => false,
                                ],
                                'state' => [
                                    'name' => 'In Progress'
                                ]
                            ],
                            [
                                'id' => 'issue-2',
                                'identifier' => 'TEST-2',
                                'title' => 'Test Issue 2',
                                'url' => 'https://linear.app/test/issue/TEST-2',
                                'estimate' => 5,
                                'priority' => 2,
                                'createdAt' => '2025-11-02T10:00:00Z',
                                'completedAt' => '2025-11-03T15:00:00Z',
                                'cycle' => [
                                    'startsAt' => '2025-11-04T00:00:00Z',
                                    'isActive' => true,
                                    'isNext' => false,
                                    'isPrevious' => false,
                                ],
                                'state' => [
                                    'name' => 'Done'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $commandTester = $this->createCommandWithMockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($linearResponse))
        ]);
        
        // When: Execute with --dry-run
        $commandTester->execute(['--dry-run' => true]);
        
        // Then: Should show preview without posting to Slack
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Fetching Linear issues...', $output);
        $this->assertStringContainsString('Dry Run - Message to be posted:', $output);
        $this->assertStringNotContainsString('Posting to Slack...', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
    
    public function testExecuteWithMissingCredentialsReturnsError(): void
    {
        // Given: No environment variables set
        putenv('LINEAR_API_KEY');
        putenv('SLACK_OAUTH_TOKEN');
        putenv('SLACK_CHANNEL_ID');
        
        $commandTester = $this->createCommandWithMockClient([]);
        
        // When: Execute without credentials
        $commandTester->execute([]);
        
        // Then: Should return error
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Missing required configuration', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }
    
    public function testHandlesLinearApiError(): void
    {
        // Given: Linear API returns error
        $errorResponse = [
            'errors' => [
                [
                    'message' => 'Authentication failed',
                    'extensions' => ['code' => 'UNAUTHENTICATED']
                ]
            ]
        ];
        
        $commandTester = $this->createCommandWithMockClient([
            new Response(401, ['Content-Type' => 'application/json'], json_encode($errorResponse))
        ]);
        
        // When: Execute command
        $commandTester->execute([]);
        
        // Then: Should handle error gracefully
        $this->assertEquals(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Error:', $output);
    }
    
    public function testFindsWeeklySlackThreadSuccessfully(): void
    {
        // Given: Mock responses for Linear and Slack APIs
        $linearResponse = [
            'data' => [
                'viewer' => [
                    'id' => 'test-user',
                    'name' => 'Test User',
                    'assignedIssues' => ['nodes' => []]
                ]
            ]
        ];
        
        $slackHistoryResponse = [
            'ok' => true,
            'messages' => [
                [
                    'text' => 'Here are my priorities for the week of November 4th',
                    'ts' => '1234567890.123456',
                    'thread_ts' => '1234567890.123456'
                ],
                [
                    'text' => 'Some other message',
                    'ts' => '1234567891.123456'
                ]
            ]
        ];
        
        $slackPostResponse = [
            'ok' => true,
            'ts' => '1234567892.123456'
        ];
        
        $commandTester = $this->createCommandWithMockClient([
            new Response(200, [], json_encode($linearResponse)),
            new Response(200, [], json_encode($slackHistoryResponse)),
            new Response(200, [], json_encode($slackPostResponse))
        ]);
        
        // When: Execute command
        $commandTester->execute([]);
        
        // Then: Should find thread and post successfully
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Finding weekly message thread...', $output);
        $this->assertStringContainsString('Successfully posted update to Slack!', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
    
    public function testHandlesSlackApiError(): void
    {
        // Given: Valid Linear response but Slack API fails
        $linearResponse = [
            'data' => [
                'viewer' => [
                    'id' => 'test-user',
                    'name' => 'Test User', 
                    'assignedIssues' => ['nodes' => []]
                ]
            ]
        ];
        
        $slackErrorResponse = [
            'ok' => false,
            'error' => 'channel_not_found'
        ];
        
        $commandTester = $this->createCommandWithMockClient([
            new Response(200, [], json_encode($linearResponse)),
            new Response(200, [], json_encode($slackErrorResponse))
        ]);
        
        // When: Execute command
        $commandTester->execute([]);
        
        // Then: Should handle Slack error
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Error:', $output);
        $this->assertEquals(1, $commandTester->getStatusCode());
    }
    
    public function testProcessesIssuesSortedByStateAndPriority(): void
    {
        // Given: Issues with different states and priorities
        $linearResponse = [
            'data' => [
                'viewer' => [
                    'id' => 'test-user',
                    'name' => 'Test User',
                    'assignedIssues' => [
                        'nodes' => [
                            [
                                'id' => '1',
                                'identifier' => 'TASK-1',
                                'title' => 'Low priority todo',
                                'url' => 'https://linear.app/test/issue/TASK-1',
                                'estimate' => 1,
                                'priority' => 4,
                                'createdAt' => '2025-11-01T10:00:00Z',
                                'completedAt' => null,
                                'cycle' => ['startsAt' => '2025-11-04T00:00:00Z', 'isActive' => true],
                                'state' => ['name' => 'Todo']
                            ],
                            [
                                'id' => '2',
                                'identifier' => 'TASK-2',
                                'title' => 'High priority in progress',
                                'url' => 'https://linear.app/test/issue/TASK-2',
                                'estimate' => 3,
                                'priority' => 1,
                                'createdAt' => '2025-11-01T11:00:00Z',
                                'completedAt' => null,
                                'cycle' => ['startsAt' => '2025-11-04T00:00:00Z', 'isActive' => true],
                                'state' => ['name' => 'In Progress']
                            ],
                            [
                                'id' => '3',
                                'identifier' => 'TASK-3',
                                'title' => 'Completed task',
                                'url' => 'https://linear.app/test/issue/TASK-3',
                                'estimate' => 2,
                                'priority' => 2,
                                'createdAt' => '2025-11-01T09:00:00Z',
                                'completedAt' => '2025-11-03T10:00:00Z',
                                'cycle' => ['startsAt' => '2025-11-04T00:00:00Z', 'isActive' => true],
                                'state' => ['name' => 'Done']
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $commandTester = $this->createCommandWithMockClient([
            new Response(200, [], json_encode($linearResponse))
        ]);
        
        // When: Execute with dry-run to see the output
        $commandTester->execute(['--dry-run' => true]);
        
        // Then: Issues should be sorted correctly
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('2025-11-04', $output);
        
        // Verify order: Done -> In Progress -> Todo
        $donePos = strpos($output, 'TASK-3');
        $inProgressPos = strpos($output, 'TASK-2');  
        $todoPos = strpos($output, 'TASK-1');
        
        $this->assertNotFalse($donePos);
        $this->assertNotFalse($inProgressPos);
        $this->assertNotFalse($todoPos);
        
        // Done should come before In Progress, which should come before Todo
        $this->assertLessThan($inProgressPos, $donePos, 'Done tasks should appear first');
        $this->assertLessThan($todoPos, $inProgressPos, 'In Progress should appear before Todo');
    }
    
    public function testFiltersOldCycles(): void
    {
        // Given: Issues from old and current cycles
        $twoWeeksAgo = (new \DateTimeImmutable('-2 weeks'))->format('Y-m-d\T00:00:00\Z');
        $thisWeek = (new \DateTimeImmutable('monday this week'))->format('Y-m-d\T00:00:00\Z');
        
        $linearResponse = [
            'data' => [
                'viewer' => [
                    'id' => 'test-user',
                    'name' => 'Test User',
                    'assignedIssues' => [
                        'nodes' => [
                            [
                                'id' => 'old-issue',
                                'identifier' => 'OLD-1',
                                'title' => 'Old cycle issue',
                                'url' => 'https://linear.app/test/issue/OLD-1',
                                'estimate' => 1,
                                'priority' => 1,
                                'createdAt' => '2025-10-01T10:00:00Z',
                                'cycle' => ['startsAt' => $twoWeeksAgo, 'isActive' => false],
                                'state' => ['name' => 'Done']
                            ],
                            [
                                'id' => 'current-issue',
                                'identifier' => 'CURRENT-1',
                                'title' => 'Current cycle issue',
                                'url' => 'https://linear.app/test/issue/CURRENT-1',
                                'estimate' => 1,
                                'priority' => 1,
                                'createdAt' => '2025-11-01T10:00:00Z',
                                'cycle' => ['startsAt' => $thisWeek, 'isActive' => true],
                                'state' => ['name' => 'In Progress']
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $commandTester = $this->createCommandWithMockClient([
            new Response(200, [], json_encode($linearResponse))
        ]);
        
        // When: Execute with dry-run
        $commandTester->execute(['--dry-run' => true]);
        
        // Then: Should only show current cycle
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('CURRENT-1', $output);
        $this->assertStringNotContainsString('OLD-1', $output);
    }
}