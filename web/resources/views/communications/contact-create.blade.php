@extends('layouts.app')
@section('title', 'New FMO Inquiry')
@section('content')
    <a class="inline-flex min-h-10 items-center text-sm font-semibold text-blue-800 hover:underline" href="{{ route('contact-fmo.index') }}">← Back to Contact FMO</a>
    <x-page-header title="New FMO Inquiry" description="Send a general facilities question to authorized FMO management." class="mt-3" />

    <div class="mx-auto mt-6 max-w-3xl">
        <x-alert type="info" title="Repair and service requests belong in Work Orders">
            For repair, maintenance, or service requests, create a Work Order so the concern can be formally processed.
            @can('work-orders.create-request')<a class="ml-1 font-semibold underline" href="{{ route('work-orders.create') }}">Create Work Order</a>@endcan
        </x-alert>

        <form class="section-card mt-5 space-y-5" method="POST" action="{{ route('contact-fmo.store') }}" enctype="multipart/form-data" data-contact-inquiry-form>
            @csrf
            <label>Category<select name="category" required data-contact-category><option value="">Choose a category</option>@foreach($categories as $category)<option value="{{ $category->value }}" @selected(old('category') === $category->value)>{{ $category->label() }}</option>@endforeach</select></label>
            <div class="hidden rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" data-existing-work-order-guidance>
                Questions about an existing request are usually best kept in that Work Order's Messages section. Select the related Work Order below so FMO has the correct context.
            </div>
            <label data-related-work-order>Related Work Order <span class="font-normal text-slate-500">(required for an existing Work Order question)</span><select name="related_work_order_id"><option value="">No related Work Order</option>@foreach($workOrders as $order)<option value="{{ $order->id }}" @selected(old('related_work_order_id') === $order->id)>{{ $order->work_order_number }} — {{ $order->subject }}</option>@endforeach</select></label>
            <label>Subject<input name="subject" value="{{ old('subject') }}" maxlength="255" required placeholder="Briefly describe your question"></label>
            <label>Message<textarea name="message" rows="6" maxlength="{{ config('communications.message_max_length') }}" placeholder="Provide the details FMO needs to understand your inquiry…">{{ old('message') }}</textarea></label>
            <x-photo-picker name="attachments" id="contact-inquiry-photos" title="Attach Photos (Optional)" help="Photos are stored privately and are visible only to you and authorized FMO management." :max-files="config('communications.attachments.max_count')" :max-size-kb="config('communications.attachments.max_size_kb')" />
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a class="btn btn-secondary" href="{{ route('contact-fmo.index') }}">Cancel</a><button class="btn btn-primary" type="submit" data-loading-text="Sending…"><x-icon name="mail" />Send Inquiry</button></div>
        </form>
    </div>
@endsection
