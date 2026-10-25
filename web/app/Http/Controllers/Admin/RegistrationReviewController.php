<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegistrationReviewController extends Controller
{
    private const PER_PAGE_OPTIONS = [20, 50, 100];

    private const STATUS_FILTERS = [
        'pending' => AccountStatus::Pending,
        'needs_correction' => AccountStatus::NeedsCorrection,
        'approved' => AccountStatus::Approved,
        'rejected' => AccountStatus::Rejected,
    ];

    public function index(Request $request): View
    {
        abort_unless($request->user()->can('users.view_pending_registrations'), 403);

        $institutionalTypes = $this->registrationScope()
            ->whereNotNull('user_type')
            ->distinct()
            ->orderBy('user_type')
            ->pluck('user_type');
        $filters = $this->filters($request, $institutionalTypes->all());
        $filteredScope = $this->applySharedFilters($this->registrationScope(), $filters);
        $countsByStatus = (clone $filteredScope)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statusCounts = collect(self::STATUS_FILTERS)
            ->mapWithKeys(fn (AccountStatus $status, string $key): array => [
                $key => (int) ($countsByStatus[$status->value] ?? 0),
            ]);
        $statusCounts['all'] = $statusCounts->sum();

        $users = $this->applyStatusFilter($filteredScope, $filters['status']);
        $this->applySort($users, $filters['sort']);
        $queryString = $this->queryString($filters);
        $users = $users->paginate($filters['per_page'])->appends($queryString);
        $returnQuery = http_build_query(array_filter([
            ...$queryString,
            'page' => $users->currentPage() > 1 ? $users->currentPage() : null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        return view('admin.registrations.index', [
            'users' => $users,
            'filters' => $filters,
            'institutionalTypes' => $institutionalTypes,
            'statusCounts' => $statusCounts,
            'queryString' => $queryString,
            'returnPath' => $request->getPathInfo().($returnQuery ? '?'.$returnQuery : ''),
            'hasActiveFilters' => $filters['search'] !== ''
                || $filters['type'] !== ''
                || $filters['registered'] !== 'any'
                || $filters['sort'] !== 'newest',
        ]);
    }

    public function show(Request $request, User $user): View
    {
        abort_unless($request->user()->can('users.view_pending_registrations'), 403);

        return view('admin.registrations.show', [
            'applicant' => $user,
            'reviews' => $user->reviews()->with('reviewer')->latest()->get(),
            'returnUrl' => $this->returnUrl($request),
        ]);
    }

    public function review(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'request_correction'])],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:action,reject,request_correction'],
            'return_to' => ['nullable', 'string', 'max:2000'],
        ]);

        $permission = match ($data['action']) {
            'approve' => 'users.approve_registration',
            'reject' => 'users.reject_registration',
            'request_correction' => 'users.request_registration_correction',
        };
        abort_unless($request->user()->can($permission), 403);

        DB::transaction(function () use ($request, $user, $data): void {
            $applicant = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($applicant->status === AccountStatus::Pending, 409);

            $next = match ($data['action']) {
                'approve' => AccountStatus::Approved,
                'reject' => AccountStatus::Rejected,
                'request_correction' => AccountStatus::NeedsCorrection,
            };
            $applicant->status = $next;
            if ($next === AccountStatus::Approved) {
                $applicant->approved_at = now();
                $applicant->approved_by = $request->user()->id;
                if ($applicant->roles()->count() === 0) {
                    $applicant->assignRole('Requester');
                }
            }
            $applicant->save();
            $applicant->reviews()->create([
                'reviewer_id' => $request->user()->id,
                'action' => match ($data['action']) {
                    'request_correction' => 'correction_requested',
                    'approve' => 'approved',
                    'reject' => 'rejected',
                },
                'previous_status' => AccountStatus::Pending->value,
                'new_status' => $next->value,
                'reason' => $data['reason'] ?? null,
            ]);
        });

        $message = match ($data['action']) {
            'approve' => 'Registration approved successfully.',
            'reject' => 'Registration rejected.',
            'request_correction' => 'Correction request sent.',
        };

        return redirect()->to($this->returnUrl($request))->with('success', $message);
    }

    private function registrationScope(): Builder
    {
        return User::query()->where(function (Builder $query): void {
            $query->whereIn('status', [
                AccountStatus::Pending,
                AccountStatus::NeedsCorrection,
                AccountStatus::Rejected,
            ])->orWhere(function (Builder $query): void {
                $query->where('status', AccountStatus::Approved)
                    ->whereNotNull('approved_at');
            });
        });
    }

    /**
     * @param  array<int, string>  $institutionalTypes
     * @return array{status: string, search: string, type: string, registered: string, from: ?string, to: ?string, sort: string, per_page: int}
     */
    private function filters(Request $request, array $institutionalTypes): array
    {
        $status = strtolower(trim((string) $request->query('status', 'pending')));
        $type = strtolower(trim((string) $request->query('type', '')));
        $registered = strtolower(trim((string) $request->query('registered', 'any')));
        $sort = strtolower(trim((string) $request->query('sort', 'newest')));
        $perPage = $request->integer('per_page', 20);

        return [
            'status' => array_key_exists($status, self::STATUS_FILTERS) || $status === 'all' ? $status : 'pending',
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 100),
            'type' => in_array($type, $institutionalTypes, true) ? $type : '',
            'registered' => in_array($registered, ['any', 'today', 'last_7_days', 'last_30_days', 'custom'], true) ? $registered : 'any',
            'from' => $this->validDate($request->query('from')),
            'to' => $this->validDate($request->query('to')),
            'sort' => in_array($sort, ['newest', 'oldest', 'applicant_asc', 'applicant_desc'], true) ? $sort : 'newest',
            'per_page' => in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 20,
        ];
    }

    /**
     * @param  array{status: string, search: string, type: string, registered: string, from: ?string, to: ?string, sort: string, per_page: int}  $filters
     */
    private function applySharedFilters(Builder $query, array $filters): Builder
    {
        $query->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search);
            });
        });
        $query->when($filters['type'] !== '', fn (Builder $query) => $query->where('user_type', $filters['type']));

        [$from, $to] = $this->dateBounds($filters);
        $query->when($from, fn (Builder $query) => $query->where('created_at', '>=', $from));
        $query->when($to, fn (Builder $query) => $query->where('created_at', '<=', $to));

        return $query;
    }

    private function applyStatusFilter(Builder $query, string $status): Builder
    {
        if ($status !== 'all') {
            $query->where('status', self::STATUS_FILTERS[$status]);
        }

        return $query;
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'applicant_asc' => $query->orderBy('name')->orderBy('id'),
            'applicant_desc' => $query->orderByDesc('name')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * @param  array{registered: string, from: ?string, to: ?string}  $filters
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function dateBounds(array $filters): array
    {
        $now = CarbonImmutable::now('Asia/Manila');

        return match ($filters['registered']) {
            'today' => [$now->startOfDay()->utc(), $now->endOfDay()->utc()],
            'last_7_days' => [$now->subDays(6)->startOfDay()->utc(), $now->endOfDay()->utc()],
            'last_30_days' => [$now->subDays(29)->startOfDay()->utc(), $now->endOfDay()->utc()],
            'custom' => [
                $filters['from'] ? CarbonImmutable::createFromFormat('Y-m-d', $filters['from'], 'Asia/Manila')->startOfDay()->utc() : null,
                $filters['to'] ? CarbonImmutable::createFromFormat('Y-m-d', $filters['to'], 'Asia/Manila')->endOfDay()->utc() : null,
            ],
            default => [null, null],
        };
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Manila');

            return $date->format('Y-m-d') === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{status: string, search: string, type: string, registered: string, from: ?string, to: ?string, sort: string, per_page: int}  $filters
     * @return array<string, string|int>
     */
    private function queryString(array $filters): array
    {
        return array_filter([
            'status' => $filters['status'],
            'search' => $filters['search'],
            'type' => $filters['type'],
            'registered' => $filters['registered'] !== 'any' ? $filters['registered'] : null,
            'from' => $filters['registered'] === 'custom' ? $filters['from'] : null,
            'to' => $filters['registered'] === 'custom' ? $filters['to'] : null,
            'sort' => $filters['sort'] !== 'newest' ? $filters['sort'] : null,
            'per_page' => $filters['per_page'] !== 20 ? $filters['per_page'] : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function returnUrl(Request $request): string
    {
        $returnTo = (string) $request->input('return_to', '');
        $path = parse_url($returnTo, PHP_URL_PATH);

        if ($returnTo !== '' && str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//') && $path === '/admin/registrations') {
            return $returnTo;
        }

        return route('registrations.index');
    }
}
