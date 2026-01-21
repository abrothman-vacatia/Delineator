<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PostWeeklyPriorities;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PostWeeklyPrioritiesUnitTest extends TestCase
{
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

    public function testProcessLinearDataSortsIssuesByStateAndPriority(): void
    {
        // Given: A command instance
        $command = new PostWeeklyPriorities();

        // And: User data with issues in different states
        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => '1',
                        'identifier' => 'TASK-1',
                        'title' => 'Todo task',
                        'url' => 'https://linear.app/test/issue/TASK-1',
                        'estimate' => 1,
                        'priority' => 3,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'completedAt' => null,
                        'state' => ['name' => 'Todo'],
                    ],
                    [
                        'id' => '2',
                        'identifier' => 'TASK-2',
                        'title' => 'In Progress task',
                        'url' => 'https://linear.app/test/issue/TASK-2',
                        'estimate' => 2,
                        'priority' => 1,
                        'createdAt' => '2025-11-01T11:00:00Z',
                        'completedAt' => null,
                        'state' => ['name' => 'In Progress'],
                    ],
                    [
                        'id' => '3',
                        'identifier' => 'TASK-3',
                        'title' => 'Done task',
                        'url' => 'https://linear.app/test/issue/TASK-3',
                        'estimate' => 3,
                        'priority' => 2,
                        'createdAt' => '2025-11-01T09:00:00Z',
                        'completedAt' => new \DateTimeImmutable('now')->format('Y-m-d\TH:i:s\Z'),
                        'state' => ['name' => 'Done'],
                    ],
                ],
            ],
        ];

        // When: Processing the data
        $result = $command->processLinearData($userData);

        // Then: Should have this week with active issues
        $this->assertCount(1, $result);
        // Week label should be a date in YYYY-MM-DD format
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result[0]['week']);

        // And: Issues should be sorted by state (Done -> In Progress -> Todo)
        $issues = $result[0]['issues'];
        $this->assertCount(3, $issues);

        // First issue should be Done
        $this->assertEquals('TASK-3', $issues[0]['identifier']);
        $this->assertEquals('Done', $issues[0]['stateName']);

        // Second issue should be In Progress
        $this->assertEquals('TASK-2', $issues[1]['identifier']);
        $this->assertEquals('In Progress', $issues[1]['stateName']);

        // Third issue should be Todo
        $this->assertEquals('TASK-1', $issues[2]['identifier']);
        $this->assertEquals('Todo', $issues[2]['stateName']);
    }

    public function testProcessLinearDataSeparatesLastWeekAndThisWeek(): void
    {
        // Given: A command instance
        $command = new PostWeeklyPriorities();

        // And: Issues from different time periods
        $lastWeek = new \DateTimeImmutable('wednesday last week')->format('Y-m-d\TH:i:s\Z');
        $twoWeeksAgo = new \DateTimeImmutable('-2 weeks')->format('Y-m-d\TH:i:s\Z');

        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => 'old',
                        'identifier' => 'OLD-1',
                        'title' => 'Very old completed issue',
                        'url' => 'https://linear.app/test/issue/OLD-1',
                        'estimate' => 1,
                        'priority' => 1,
                        'createdAt' => '2025-10-01T10:00:00Z',
                        'completedAt' => $twoWeeksAgo,
                        'state' => ['name' => 'Done'],
                    ],
                    [
                        'id' => 'lastweek',
                        'identifier' => 'LASTWEEK-1',
                        'title' => 'Last week completed issue',
                        'url' => 'https://linear.app/test/issue/LASTWEEK-1',
                        'estimate' => 1,
                        'priority' => 1,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'completedAt' => $lastWeek,
                        'state' => ['name' => 'Done'],
                    ],
                    [
                        'id' => 'current',
                        'identifier' => 'CURRENT-1',
                        'title' => 'This week in progress',
                        'url' => 'https://linear.app/test/issue/CURRENT-1',
                        'estimate' => 1,
                        'priority' => 1,
                        'createdAt' => '2025-11-04T10:00:00Z',
                        'state' => ['name' => 'In Progress'],
                    ],
                    [
                        'id' => 'backlog',
                        'identifier' => 'BACKLOG-1',
                        'title' => 'Backlog item',
                        'url' => 'https://linear.app/test/issue/BACKLOG-1',
                        'estimate' => 1,
                        'priority' => 1,
                        'createdAt' => '2025-11-04T10:00:00Z',
                        'state' => ['name' => 'Backlog'],
                    ],
                ],
            ],
        ];

        // When: Processing the data
        $result = $command->processLinearData($userData);

        // Then: Should have two weeks
        $this->assertCount(2, $result);

        // Last week should be first and contain only last week's completed issue
        $lastWeekData = $result[0];
        // Week label should be a date in YYYY-MM-DD format (last Monday)
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $lastWeekData['week']);
        $this->assertCount(1, $lastWeekData['issues']);
        $this->assertEquals('LASTWEEK-1', $lastWeekData['issues'][0]['identifier']);

        // This week should be second and contain current in-progress issue
        $thisWeek = $result[1];
        // Week label should be a date in YYYY-MM-DD format (this Monday)
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $thisWeek['week']);
        $this->assertCount(1, $thisWeek['issues']);
        $this->assertEquals('CURRENT-1', $thisWeek['issues'][0]['identifier']);

        // Old completed issue and backlog should not appear
        $allIdentifiers = [];
        foreach ($result as $week) {
            foreach ($week['issues'] as $issue) {
                $allIdentifiers[] = $issue['identifier'];
            }
        }
        $this->assertNotContains('OLD-1', $allIdentifiers);
        $this->assertNotContains('BACKLOG-1', $allIdentifiers);
    }

    public function testProcessLinearDataHandlesEmptyAssignedIssues(): void
    {
        // Given: A command instance
        $command = new PostWeeklyPriorities();

        // And: User data with no assigned issues
        $userData = [
            'assignedIssues' => [
                'nodes' => [],
            ],
        ];

        // When: Processing the data
        $result = $command->processLinearData($userData);

        // Then: Should return empty array
        $this->assertEmpty($result);
    }

    public function testProcessLinearDataHandlesMissingFields(): void
    {
        // Given: A command instance
        $command = new PostWeeklyPriorities();

        // And: User data with issues missing optional fields
        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => '1',
                        'identifier' => 'TASK-1',
                        'title' => 'Task without estimate',
                        'url' => 'https://linear.app/test/issue/TASK-1',
                        'priority' => 1,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'state' => ['name' => 'Todo'],
                        // Note: no estimate, no completedAt
                    ],
                ],
            ],
        ];

        // When: Processing the data
        $result = $command->processLinearData($userData);

        // Then: Should handle gracefully and include the Todo task in this week
        $this->assertCount(1, $result);
        // Week label should be a date in YYYY-MM-DD format
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result[0]['week']);
        $this->assertCount(1, $result[0]['issues']);
        $this->assertEquals('TASK-1', $result[0]['issues'][0]['identifier']);
    }

    public function testDryRunDoesNotMakeHttpCalls(): void
    {
        // Given: Mock handlers for two Linear API calls (last week and this week)
        $mockHandler = new MockHandler([
            // Response for last week query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'viewer' => [
                        'id' => 'test-user',
                        'name' => 'Test User',
                        'assignedIssues' => [
                            'nodes' => [],
                        ],
                    ],
                ],
            ])),
            // Response for this week query
            new Response(200, [], (string) json_encode([
                'data' => [
                    'viewer' => [
                        'id' => 'test-user',
                        'name' => 'Test User',
                        'assignedIssues' => [
                            'nodes' => [
                                [
                                    'id' => '1',
                                    'identifier' => 'TEST-1',
                                    'title' => 'Test Issue',
                                    'url' => 'https://linear.app/test/issue/TEST-1',
                                    'estimate' => 1,
                                    'priority' => 1,
                                    'createdAt' => '2025-11-01T10:00:00Z',
                                    'state' => ['name' => 'In Progress'],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        // And: A command with the mocked client
        $command = new PostWeeklyPriorities($mockClient);

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);

        // When: Running with dry-run
        $commandTester->execute(['--dry-run' => true]);

        // Then: Should complete successfully
        $this->assertEquals(0, $commandTester->getStatusCode());

        // And: Output should indicate dry run
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Dry Run', $output);

        // And: Mock handler should have been called twice (for both Linear API queries)
        $this->assertEquals(0, $mockHandler->count(), 'Two requests should have been made to Linear API');
    }

    public function testFormatIssuesForSlackUsesRichTextBlocks(): void
    {
        // Given: A command instance
        $command = new PostWeeklyPriorities();

        // And: Sample weekly issues data
        $weeklyIssues = [
            [
                'week' => '2026-01-06',
                'issues' => [
                    [
                        'identifier' => 'TEST-1',
                        'title' => 'Test Issue',
                        'stateSymbol' => 'done_linear',
                    ],
                    [
                        'identifier' => 'TEST-2',
                        'title' => 'Another Issue',
                        'stateSymbol' => 'in_progress_linear',
                    ],
                ],
            ],
        ];

        // When: Formatting for Slack
        $reflectionClass = new \ReflectionClass(PostWeeklyPriorities::class);
        $method = $reflectionClass->getMethod('formatIssuesForSlack');
        $result = $method->invoke($command, $weeklyIssues);

        // Then: Should return valid JSON
        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('blocks', $decoded);

        $blocks = $decoded['blocks'];
        $this->assertIsArray($blocks);
        $this->assertCount(2, $blocks); // Header + list

        // First block should be header
        $this->assertIsArray($blocks[0]);
        $headerBlock = $blocks[0];
        $this->assertEquals('header', $headerBlock['type']);
        $this->assertArrayHasKey('text', $headerBlock);
        $this->assertIsArray($headerBlock['text']);
        $this->assertEquals('plain_text', $headerBlock['text']['type']);
        $this->assertEquals('2026-01-06', $headerBlock['text']['text']);

        // Second block should be rich_text ordered list
        $this->assertIsArray($blocks[1]);
        $listBlock = $blocks[1];
        $this->assertEquals('rich_text', $listBlock['type']);
        $this->assertArrayHasKey('elements', $listBlock);
        $this->assertIsArray($listBlock['elements']);
        $this->assertIsArray($listBlock['elements'][0]);
        $this->assertEquals('rich_text_list', $listBlock['elements'][0]['type']);
        $this->assertEquals('ordered', $listBlock['elements'][0]['style']);

        // Should have 2 list items
        $this->assertArrayHasKey('elements', $listBlock['elements'][0]);
        $this->assertIsArray($listBlock['elements'][0]['elements']);
        $listItems = $listBlock['elements'][0]['elements'];
        $this->assertCount(2, $listItems);

        // First item should contain emoji and link
        $this->assertIsArray($listItems[0]);
        $firstItem = $listItems[0];
        $this->assertEquals('rich_text_section', $firstItem['type']);
        $this->assertArrayHasKey('elements', $firstItem);
        $this->assertIsArray($firstItem['elements']);
        $itemElements = $firstItem['elements'];

        // Should have emoji, space, link, and text elements
        $this->assertIsArray($itemElements[0]);
        $this->assertEquals('emoji', $itemElements[0]['type']);
        $this->assertEquals('done_linear', $itemElements[0]['name']);
        $this->assertIsArray($itemElements[1]);
        $this->assertEquals('text', $itemElements[1]['type']);
        $this->assertEquals(' ', $itemElements[1]['text']);
        $this->assertIsArray($itemElements[2]);
        $this->assertEquals('link', $itemElements[2]['type']);
        $this->assertEquals('TEST-1', $itemElements[2]['text']);
        $this->assertIsString($itemElements[2]['url']);
        $this->assertStringContainsString('TEST-1', $itemElements[2]['url']);
        $this->assertIsArray($itemElements[3]);
        $this->assertEquals('text', $itemElements[3]['type']);
        $this->assertEquals(' - Test Issue', $itemElements[3]['text']);
    }

    public function testMissingEnvironmentVariablesReturnError(): void
    {
        // Given: No environment variables
        putenv('LINEAR_API_KEY');
        putenv('SLACK_OAUTH_TOKEN');
        putenv('SLACK_CHANNEL_ID');

        // And: A command
        $command = new PostWeeklyPriorities();

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);

        // When: Running the command
        $commandTester->execute([]);

        // Then: Should return failure
        $this->assertEquals(1, $commandTester->getStatusCode());

        // And: Should show error message
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Missing required configuration', $output);
    }

    // HTTP/Network Failure Scenarios
    public function testLinearApiNetworkFailure(): void
    {
        $mockHandler = new MockHandler([
            new ConnectException('Network error', new Request('POST', 'https://api.linear.app/graphql')),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        $command = new PostWeeklyPriorities($mockClient);

        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Network error', $output);
    }

    public function testLinearApi500Error(): void
    {
        $mockHandler = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        $command = new PostWeeklyPriorities($mockClient);

        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    public function testLinearApiInvalidJson(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'invalid json{'),
            new Response(200, [], 'invalid json{'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        $command = new PostWeeklyPriorities($mockClient);

        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('No user data found from Linear API', $output);
    }

    public function testSlackApiFailure(): void
    {
        $mockHandler = new MockHandler([
            // Linear API responses (success)
            new Response(200, [], (string) json_encode([
                'data' => [
                    'viewer' => [
                        'id' => 'test-user',
                        'name' => 'Test User',
                        'assignedIssues' => ['nodes' => []],
                    ],
                ],
            ])),
            new Response(200, [], (string) json_encode([
                'data' => [
                    'viewer' => [
                        'id' => 'test-user',
                        'name' => 'Test User',
                        'assignedIssues' => [
                            'nodes' => [[
                                'id' => '1',
                                'identifier' => 'TEST-1',
                                'title' => 'Test Issue',
                                'url' => 'https://linear.app/test/issue/TEST-1',
                                'estimate' => 1,
                                'priority' => 1,
                                'createdAt' => '2025-11-01T10:00:00Z',
                                'state' => ['name' => 'In Progress'],
                            ]],
                        ],
                    ],
                ],
            ])),
            // Slack API failure
            new Response(200, [], (string) json_encode(['ok' => false, 'error' => 'invalid_token'])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        $command = new PostWeeklyPriorities($mockClient);

        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('invalid_token', $output);
    }

    // Complex Data Processing Tests
    public function testProcessLinearDataWithComplexBlockingScenarios(): void
    {
        $command = new PostWeeklyPriorities();

        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => '1',
                        'identifier' => 'BLOCKED-1',
                        'title' => 'Blocked Task',
                        'url' => 'https://linear.app/test/issue/BLOCKED-1',
                        'estimate' => 1,
                        'priority' => 1,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'state' => ['name' => 'In Progress'],
                        'inverseRelations' => [
                            'nodes' => [
                                [
                                    'type' => 'blocks',
                                    'issue' => [
                                        'identifier' => 'BLOCKER-1',
                                        'title' => 'Active Blocker',
                                        'url' => 'https://linear.app/test/issue/BLOCKER-1',
                                        'state' => ['name' => 'In Progress'],
                                    ],
                                ],
                                [
                                    'type' => 'blocks',
                                    'issue' => [
                                        'identifier' => 'BLOCKER-2',
                                        'title' => 'Completed Blocker',
                                        'url' => 'https://linear.app/test/issue/BLOCKER-2',
                                        'state' => ['name' => 'Done'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $command->processLinearData($userData);

        $this->assertCount(1, $result);
        $issues = $result[0]['issues'];
        $this->assertCount(1, $issues);

        // Should be marked as blocked due to active blocker
        $this->assertEquals('blocked_linear', $issues[0]['stateSymbol']);

        // Should have only the active blocker (completed one filtered out)
        /** @var array<int, array<string, mixed>> $blockedBy */
        $blockedBy = $issues[0]['blockedBy'];
        $this->assertCount(1, $blockedBy);
        /** @var array<string, mixed> $firstBlocker */
        $firstBlocker = $blockedBy[0];
        /** @var array<string, mixed> $blockerIssue */
        $blockerIssue = $firstBlocker['issue'];
        $this->assertEquals('BLOCKER-1', $blockerIssue['identifier']);
    }

    public function testProcessLinearDataWithMalformedInverseRelations(): void
    {
        $command = new PostWeeklyPriorities();

        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => '1',
                        'identifier' => 'TASK-1',
                        'title' => 'Task with malformed relations',
                        'url' => 'https://linear.app/test/issue/TASK-1',
                        'priority' => 1,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'state' => ['name' => 'Todo'],
                        'inverseRelations' => [
                            'nodes' => [
                                // Missing issue data
                                ['type' => 'blocks'],
                                // Missing state in issue
                                [
                                    'type' => 'blocks',
                                    'issue' => [
                                        'identifier' => 'BAD-1',
                                        'title' => 'No State',
                                    ],
                                ],
                                // Non-array issue
                                ['type' => 'blocks', 'issue' => 'not-an-array'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $command->processLinearData($userData);

        $this->assertCount(1, $result);
        $issues = $result[0]['issues'];
        $this->assertCount(1, $issues);

        // Should not be marked as blocked due to malformed blockers
        $this->assertEquals('todo_linear', $issues[0]['stateSymbol']);
        $this->assertEmpty($issues[0]['blockedBy']);
    }

    public function testProcessLinearDataWithNullInverseRelations(): void
    {
        $command = new PostWeeklyPriorities();

        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => '1',
                        'identifier' => 'TASK-1',
                        'title' => 'Task without inverse relations',
                        'url' => 'https://linear.app/test/issue/TASK-1',
                        'priority' => 1,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'state' => ['name' => 'Todo'],
                        'inverseRelations' => null,
                    ],
                ],
            ],
        ];

        $result = $command->processLinearData($userData);

        $this->assertCount(1, $result);
        $issues = $result[0]['issues'];
        $this->assertCount(1, $issues);

        $this->assertEquals('todo_linear', $issues[0]['stateSymbol']);
        $this->assertEmpty($issues[0]['blockedBy']);
    }

    public function testProcessLinearDataWithVeryLongTitles(): void
    {
        $command = new PostWeeklyPriorities();
        $longTitle = str_repeat('Very long issue title ', 50); // 1000+ characters

        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => '1',
                        'identifier' => 'LONG-1',
                        'title' => $longTitle,
                        'url' => 'https://linear.app/test/issue/LONG-1',
                        'priority' => 1,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'state' => ['name' => 'Todo'],
                    ],
                ],
            ],
        ];

        $result = $command->processLinearData($userData);

        $this->assertCount(1, $result);
        $issues = $result[0]['issues'];
        $this->assertCount(1, $issues);
        $this->assertEquals($longTitle, $issues[0]['title']);
    }

    // Date/Time Boundary Tests
    public function testProcessLinearDataWeekTransitionBoundary(): void
    {
        $command = new PostWeeklyPriorities();

        // Issue completed exactly at last Monday midnight
        $lastMonday = new \DateTimeImmutable('monday last week');
        $lastMondayMidnight = $lastMonday->format('Y-m-d\TH:i:s');

        // Issue completed exactly at this Monday midnight (should be this week)
        $thisMonday = new \DateTimeImmutable('monday this week');
        $thisMondayMidnight = $thisMonday->format('Y-m-d\TH:i:s');

        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => '1',
                        'identifier' => 'LAST-MONDAY',
                        'title' => 'Completed last Monday midnight',
                        'url' => 'https://linear.app/test/issue/LAST-MONDAY',
                        'priority' => 1,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'completedAt' => $lastMondayMidnight,
                        'state' => ['name' => 'Done'],
                    ],
                    [
                        'id' => '2',
                        'identifier' => 'THIS-MONDAY',
                        'title' => 'Completed this Monday midnight',
                        'url' => 'https://linear.app/test/issue/THIS-MONDAY',
                        'priority' => 1,
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'completedAt' => $thisMondayMidnight,
                        'state' => ['name' => 'Done'],
                    ],
                ],
            ],
        ];

        $result = $command->processLinearData($userData);

        $this->assertCount(2, $result);

        // Last week should contain the last Monday issue
        $lastWeekIssues = $result[0]['issues'];
        $this->assertCount(1, $lastWeekIssues);
        $this->assertEquals('LAST-MONDAY', $lastWeekIssues[0]['identifier']);

        // This week should contain the this Monday issue
        $thisWeekIssues = $result[1]['issues'];
        $this->assertCount(1, $thisWeekIssues);
        $this->assertEquals('THIS-MONDAY', $thisWeekIssues[0]['identifier']);
    }

    public function testProcessLinearDataWithDifferentPrioritySorting(): void
    {
        $command = new PostWeeklyPriorities();

        $userData = [
            'assignedIssues' => [
                'nodes' => [
                    [
                        'id' => '1',
                        'identifier' => 'LOW-PRIO',
                        'title' => 'Low priority task',
                        'url' => 'https://linear.app/test/issue/LOW-PRIO',
                        'estimate' => 1,
                        'priority' => 4, // Low priority
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'state' => ['name' => 'Todo'],
                    ],
                    [
                        'id' => '2',
                        'identifier' => 'HIGH-PRIO',
                        'title' => 'High priority task',
                        'url' => 'https://linear.app/test/issue/HIGH-PRIO',
                        'estimate' => 1,
                        'priority' => 1, // High priority
                        'createdAt' => '2025-11-01T10:00:00Z',
                        'state' => ['name' => 'Todo'],
                    ],
                ],
            ],
        ];

        $result = $command->processLinearData($userData);

        $this->assertCount(1, $result);
        $issues = $result[0]['issues'];
        $this->assertCount(2, $issues);

        // High priority should come first (lower number = higher priority)
        $this->assertEquals('HIGH-PRIO', $issues[0]['identifier']);
        $this->assertEquals('LOW-PRIO', $issues[1]['identifier']);
    }

    // Thread Detection Tests - These test findWeeklyMessageThread via reflection
    public function testFindWeeklyMessageThreadSuccess(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], (string) json_encode([
                'ok' => true,
                'messages' => [
                    [
                        'text' => 'My priorities for the week of Jan 6',
                        'ts' => '1234567890.123',
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        $command = new PostWeeklyPriorities($mockClient);

        // Set required properties via reflection
        $reflectionClass = new \ReflectionClass(PostWeeklyPriorities::class);
        $slackTokenProperty = $reflectionClass->getProperty('slackToken');
        $channelIdProperty = $reflectionClass->getProperty('channelId');
        $slackTokenProperty->setValue($command, 'test-token');
        $channelIdProperty->setValue($command, 'test-channel');

        $method = $reflectionClass->getMethod('findWeeklyMessageThread');
        $result = $method->invoke($command);

        $this->assertEquals('1234567890.123', $result);
    }

    public function testFindWeeklyMessageThreadNotFound(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], (string) json_encode([
                'ok' => true,
                'messages' => [
                    ['text' => 'Some other message', 'ts' => '1234567890.123'],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        $command = new PostWeeklyPriorities($mockClient);

        // Set required properties via reflection
        $reflectionClass = new \ReflectionClass(PostWeeklyPriorities::class);
        $slackTokenProperty = $reflectionClass->getProperty('slackToken');
        $channelIdProperty = $reflectionClass->getProperty('channelId');
        $slackTokenProperty->setValue($command, 'test-token');
        $channelIdProperty->setValue($command, 'test-channel');

        $method = $reflectionClass->getMethod('findWeeklyMessageThread');
        $result = $method->invoke($command);

        $this->assertNull($result);
    }

    public function testFindWeeklyMessageThreadApiFailure(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], (string) json_encode([
                'ok' => false,
                'error' => 'channel_not_found',
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        $command = new PostWeeklyPriorities($mockClient);

        // Set required properties via reflection
        $reflectionClass = new \ReflectionClass(PostWeeklyPriorities::class);
        $slackTokenProperty = $reflectionClass->getProperty('slackToken');
        $channelIdProperty = $reflectionClass->getProperty('channelId');
        $slackTokenProperty->setValue($command, 'test-token');
        $channelIdProperty->setValue($command, 'test-channel');

        $method = $reflectionClass->getMethod('findWeeklyMessageThread');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to get channel history: channel_not_found');

        $method->invoke($command);
    }

    public function testFindWeeklyMessageThreadIgnoresReplies(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], (string) json_encode([
                'ok' => true,
                'messages' => [
                    // This is a reply (thread_ts != ts), should be ignored
                    [
                        'text' => 'priorities for the week of',
                        'ts' => '1234567890.456',
                        'thread_ts' => '1234567890.123',
                    ],
                    // This is a parent message, should be found
                    [
                        'text' => 'My priorities for the week of Jan 13',
                        'ts' => '1234567890.789',
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        $command = new PostWeeklyPriorities($mockClient);

        // Set required properties via reflection
        $reflectionClass = new \ReflectionClass(PostWeeklyPriorities::class);
        $slackTokenProperty = $reflectionClass->getProperty('slackToken');
        $channelIdProperty = $reflectionClass->getProperty('channelId');
        $slackTokenProperty->setValue($command, 'test-token');
        $channelIdProperty->setValue($command, 'test-channel');

        $method = $reflectionClass->getMethod('findWeeklyMessageThread');
        $result = $method->invoke($command);

        $this->assertEquals('1234567890.789', $result);
    }

    // Additional Slack Posting Tests
    public function testPostToSlackWithThread(): void
    {
        $mockHandler = new MockHandler([
            // Linear API responses
            new Response(200, [], (string) json_encode([
                'data' => [
                    'viewer' => [
                        'id' => 'test-user',
                        'name' => 'Test User',
                        'assignedIssues' => ['nodes' => []],
                    ],
                ],
            ])),
            new Response(200, [], (string) json_encode([
                'data' => [
                    'viewer' => [
                        'id' => 'test-user',
                        'name' => 'Test User',
                        'assignedIssues' => [
                            'nodes' => [[
                                'id' => '1',
                                'identifier' => 'TEST-1',
                                'title' => 'Test Issue',
                                'url' => 'https://linear.app/test/issue/TEST-1',
                                'estimate' => 1,
                                'priority' => 1,
                                'createdAt' => '2025-11-01T10:00:00Z',
                                'state' => ['name' => 'In Progress'],
                            ]],
                        ],
                    ],
                ],
            ])),
            // Slack thread search (found)
            new Response(200, [], (string) json_encode([
                'ok' => true,
                'messages' => [
                    ['text' => 'priorities for the week of', 'ts' => '1234567890.123'],
                ],
            ])),
            // Slack post message (success)
            new Response(200, [], (string) json_encode(['ok' => true])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        $command = new PostWeeklyPriorities($mockClient);

        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Successfully posted update to Slack', $output);
    }

    public function testPostToSlackWithoutThread(): void
    {
        $mockHandler = new MockHandler([
            // Linear API responses
            new Response(200, [], (string) json_encode([
                'data' => [
                    'viewer' => [
                        'id' => 'test-user',
                        'name' => 'Test User',
                        'assignedIssues' => ['nodes' => []],
                    ],
                ],
            ])),
            new Response(200, [], (string) json_encode([
                'data' => [
                    'viewer' => [
                        'id' => 'test-user',
                        'name' => 'Test User',
                        'assignedIssues' => [
                            'nodes' => [[
                                'id' => '1',
                                'identifier' => 'TEST-1',
                                'title' => 'Test Issue',
                                'url' => 'https://linear.app/test/issue/TEST-1',
                                'estimate' => 1,
                                'priority' => 1,
                                'createdAt' => '2025-11-01T10:00:00Z',
                                'state' => ['name' => 'In Progress'],
                            ]],
                        ],
                    ],
                ],
            ])),
            // Slack thread search (not found)
            new Response(200, [], (string) json_encode([
                'ok' => true,
                'messages' => [],
            ])),
            // Slack post message (success)
            new Response(200, [], (string) json_encode(['ok' => true])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        $command = new PostWeeklyPriorities($mockClient);

        $application = new Application();
        $application->addCommand($command);
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Could not find weekly message thread', $output);
        $this->assertStringContainsString('Successfully posted update to Slack', $output);
    }

    public function testFormatIssuesForSlackWithBlockedIssues(): void
    {
        $command = new PostWeeklyPriorities();

        $weeklyIssues = [
            [
                'week' => '2026-01-06',
                'issues' => [
                    [
                        'identifier' => 'BLOCKED-1',
                        'title' => 'Blocked Issue',
                        'stateSymbol' => 'blocked_linear',
                        'blockedBy' => [
                            [
                                'issue' => [
                                    'identifier' => 'BLOCKER-1',
                                    'title' => 'Blocking Issue',
                                    'url' => 'https://linear.app/test/issue/BLOCKER-1',
                                    'state' => ['name' => 'In Progress'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $reflectionClass = new \ReflectionClass(PostWeeklyPriorities::class);
        $method = $reflectionClass->getMethod('formatIssuesForSlack');
        $result = $method->invoke($command, $weeklyIssues);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('blocks', $decoded);

        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = $decoded['blocks'];
        $this->assertCount(2, $blocks); // Header + rich text with both ordered and bullet lists

        // Verify the blocked issue structure
        /** @var array<string, mixed> $richTextBlock */
        $richTextBlock = $blocks[1];
        $this->assertEquals('rich_text', $richTextBlock['type']);
        $this->assertArrayHasKey('elements', $richTextBlock);

        // Should have ordered list + bullet list for blockers
        /** @var array<int, array<string, mixed>> $elements */
        $elements = $richTextBlock['elements'];
        $this->assertCount(2, $elements);
        /** @var array<string, mixed> $firstElement */
        $firstElement = $elements[0];
        $this->assertEquals('rich_text_list', $firstElement['type']);
        $this->assertEquals('ordered', $firstElement['style']);
        /** @var array<string, mixed> $secondElement */
        $secondElement = $elements[1];
        $this->assertEquals('rich_text_list', $secondElement['type']);
        $this->assertEquals('bullet', $secondElement['style']);
    }

    public function testExecuteGraphQLQueryInvalidResponse(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], ''),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        $command = new PostWeeklyPriorities($mockClient);

        // Set required properties via reflection
        $reflectionClass = new \ReflectionClass(PostWeeklyPriorities::class);
        $linearApiKeyProperty = $reflectionClass->getProperty('linearApiKey');
        $linearApiKeyProperty->setValue($command, 'test-api-key');

        $method = $reflectionClass->getMethod('executeGraphQLQuery');
        $result = $method->invoke($command, 'query { viewer { id } }');

        $this->assertEquals([], $result);
    }
}
