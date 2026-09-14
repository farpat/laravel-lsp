<?php

use App\Lsp\Document;
use App\Lsp\Features\Eloquent\AttributeDeclarations;
use App\Lsp\Features\Eloquent\EloquentAttributeDocumentMapper;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\PhpMembers;
use App\Lsp\Support\WorkspaceIndex;

/**
 * Build a throwaway project whose index already knows the given models.
 *
 * @param  array<string, string>  $files
 * @param  array<string, array<string, mixed>>  $models
 * @return array{project: Project, path: string}
 */
function eloquentProject(array $files, array $models): array
{
    $path = sys_get_temp_dir() . '/lsp-eloquent-' . uniqid();

    foreach ($files as $name => $content) {
        @mkdir(dirname($path . '/' . $name), 0777, true);
        file_put_contents($path . '/' . $name, $content);
    }

    $index = new class($models) extends ProjectIndex
    {
        public function __construct(array $models)
        {
            $this->loaded['models'] = $models;
        }
    };

    $project = new Project(FileUri::fromPath($path), [], $index, new ScriptRunner($path, ['php']));

    return ['project' => $project, 'path' => $path];
}

/**
 * Get the links of the given PHP source, each as its text and its target relative to the project.
 *
 * @param  array<string, string>  $files
 * @param  array<string, array<string, mixed>>  $models
 * @return array<int, array{text: string, target: string}>
 */
function eloquentLinks(string $source, array $files, array $models): array
{
    ['project' => $project, 'path' => $path] = eloquentProject($files, $models);

    $index = new WorkspaceIndex($project);
    $mapper = new EloquentAttributeDocumentMapper($project, new AttributeDeclarations($project, $index, new PhpMembers($index)));
    $document = new Document((string) FileUri::fromPath($path . '/app/Handler.php'), $source);

    // A chain is detected from its last call, so the links are put back in reading order.
    $links = $mapper->links($document);
    usort($links, fn (array $a, array $b): int => [$a['range']['start']['line'], $a['range']['start']['character']] <=> [$b['range']['start']['line'], $b['range']['start']['character']]);

    return array_map(fn (array $link): array => [
        'text'   => $document->textInRange($link['range']),
        'target' => str_replace((string) FileUri::fromPath($path) . '/', '', (string) $link['target']),
    ], $links);
}

/**
 * @return array<string, array<string, mixed>>
 */
function eloquentModels(): array
{
    return [
        'App\\Models\\AccountDeletion' => [
            'class'      => 'App\\Models\\AccountDeletion',
            'table'      => 'account_deletions',
            'path'       => 'app/Models/AccountDeletion.php',
            'builder'    => 'App\\EloquentBuilders\\AccountDeletionBuilder',
            'attributes' => [
                ['name' => 'id'],
                ['name' => 'status'],
                ['name' => 'customer_id'],
                ['name' => 'closed_notified_at'],
            ],
        ],
        'App\\Models\\Customer' => [
            'class'      => 'App\\Models\\Customer',
            'table'      => 'customers',
            'path'       => 'app/Models/Customer.php',
            'builder'    => null,
            'attributes' => [
                ['name' => 'id'],
                ['name' => 'name'],
            ],
        ],
    ];
}

const ACCOUNT_DELETION_MODEL = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property-read Customer $customer
 *
 * @mixin IdeHelperAccountDeletion
 */
#[UseEloquentBuilder(AccountDeletionBuilder::class)]
final class AccountDeletion extends Model
{
}
PHP;

const IDE_HELPER = <<<'PHP'
<?php

namespace App\Models{
/**
 * @property int $id
 * @property \App\Enums\AccountDeletionStatusEnum $status
 * @property int $customer_id
 * @property \Illuminate\Support\Carbon|null $closed_notified_at
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperAccountDeletion {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 */
	final class IdeHelperCustomer {}
}
PHP;

const CUSTOMER_MODEL = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
class Customer extends Model
{
}
PHP;

test('an array key written to a model links to the property ide-helper generated', function () {
    $links = eloquentLinks(<<<'PHP'
    <?php

    use App\Models\AccountDeletion;

    function run(AccountDeletion $accountDeletion): void
    {
        $accountDeletion->update(['closed_notified_at' => now(), 'status' => 'pending']);
    }
    PHP, [
        'app/Models/AccountDeletion.php' => ACCOUNT_DELETION_MODEL,
        'helpers/_ide_helper_models.php' => IDE_HELPER,
    ], eloquentModels());

    expect($links)->toBe([
        ['text' => 'closed_notified_at', 'target' => 'helpers/_ide_helper_models.php#L8'],
        ['text' => 'status', 'target' => 'helpers/_ide_helper_models.php#L6'],
    ]);
});

test('a column read by a query links to the property the model documents itself', function () {
    $links = eloquentLinks(<<<'PHP'
    <?php

    use App\Models\Customer;

    Customer::query()->where('name', 'x')->orderBy('id')->pluck('name', 'id');
    PHP, [
        'app/Models/Customer.php' => CUSTOMER_MODEL,
    ], eloquentModels());

    expect($links)->toBe([
        ['text' => 'name', 'target' => 'app/Models/Customer.php#L9'],
        ['text' => 'id', 'target' => 'app/Models/Customer.php#L8'],
        ['text' => 'name', 'target' => 'app/Models/Customer.php#L9'],
        ['text' => 'id', 'target' => 'app/Models/Customer.php#L8'],
    ]);
});

test('an attribute nothing documents links to the model class', function () {
    $links = eloquentLinks(<<<'PHP'
    <?php

    use App\Models\Customer;

    Customer::create(['name' => 'x']);
    PHP, [
        'app/Models/Customer.php' => "<?php\n\nnamespace App\\Models;\n\nclass Customer extends Model\n{\n}\n",
    ], eloquentModels());

    expect($links)->toBe([
        ['text' => 'name', 'target' => 'app/Models/Customer.php#L5'],
    ]);
});

test('a column qualified by a table links to the model mapped to that table', function () {
    $links = eloquentLinks(<<<'PHP'
    <?php

    use App\Models\AccountDeletion;

    AccountDeletion::query()->whereColumn('account_deletions.customer_id', 'customers.id');
    PHP, [
        'app/Models/AccountDeletion.php' => ACCOUNT_DELETION_MODEL,
        'app/Models/Customer.php'        => CUSTOMER_MODEL,
        'helpers/_ide_helper_models.php' => IDE_HELPER,
    ], eloquentModels());

    expect($links)->toBe([
        ['text' => 'account_deletions.customer_id', 'target' => 'helpers/_ide_helper_models.php#L7'],
        ['text' => 'customers.id', 'target' => 'app/Models/Customer.php#L8'],
    ]);
});

test('a call on the builder of a model reads the attributes of that model', function () {
    $links = eloquentLinks(<<<'PHP'
    <?php

    use App\EloquentBuilders\AccountDeletionBuilder;

    function scope(AccountDeletionBuilder $deletions): void
    {
        $deletions->where('status', 'pending')->select(['id', 'status']);
    }
    PHP, [
        'app/Models/AccountDeletion.php' => ACCOUNT_DELETION_MODEL,
        'helpers/_ide_helper_models.php' => IDE_HELPER,
    ], eloquentModels());

    expect(array_column($links, 'text'))->toBe(['status', 'id', 'status']);
});

test('a string that names no attribute is left alone', function () {
    $links = eloquentLinks(<<<'PHP'
    <?php

    use App\Models\Customer;

    Customer::query()->where('unknown', 'name')->update(['unknown' => 'name']);
    Customer::create(['name' => 'name']);
    PHP, [
        'app/Models/Customer.php' => CUSTOMER_MODEL,
    ], eloquentModels());

    expect(array_column($links, 'text'))->toBe(['name']);
});
