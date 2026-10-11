@if ($management)
    <details id="management-actions" class="section-card scroll-mt-24">
        <summary class="cursor-pointer text-lg font-semibold text-slate-900">Management actions</summary>
        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            @if ($order->status === \App\Enums\WorkOrderStatus::Submitted)
                @can('work_orders.screen')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'begin-screening']) }}">@csrf<button class="btn btn-primary" type="submit">Begin Screening</button></form>@endcan
            @endif
            @if ($order->status === \App\Enums\WorkOrderStatus::ForScreening)
                @can('work_orders.request_information')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'request-information']) }}">@csrf<label>Request more information<textarea name="requester_message" required></textarea></label><button class="btn btn-secondary mt-3" type="submit">Send to requester</button></form>@endcan
                @can('work_orders.recommend')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'recommend-approval']) }}">@csrf<label>Internal screening note<textarea name="internal_note"></textarea></label><button class="btn btn-primary mt-3" type="submit">Recommend Approval</button></form>@endcan
                @can('work_orders.recommend')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'recommend-disapproval']) }}">@csrf<label>Internal reason<textarea name="internal_note" required></textarea></label><button class="btn btn-secondary mt-3" type="submit">Recommend Disapproval</button></form>@endcan
            @endif
            @if ($order->status === \App\Enums\WorkOrderStatus::ForApproval)
                @can('work_orders.approve')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'approve']) }}">@csrf<label>Internal approval note<textarea name="internal_note"></textarea></label><button class="btn btn-success mt-3" type="submit">Approve Work Order</button></form>@endcan
                @can('work_orders.disapprove')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'disapprove']) }}">@csrf<label>Reason shown to requester<textarea name="requester_message" required></textarea></label><button class="btn btn-danger mt-3" type="submit">Disapprove</button></form>@endcan
                @can('work_orders.return_to_screening')<form method="POST" action="{{ route('work-orders.workflow', [$order, 'return-to-screening']) }}">@csrf<label>Internal return reason<textarea name="internal_note" required></textarea></label><button class="btn btn-secondary mt-3" type="submit">Return to Screening</button></form>@endcan
            @endif
        </div>
    </details>
@endif
