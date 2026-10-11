@extends('layouts.app')
@section('title', 'Session Expired')
@section('content')
    <x-error-page code="419" title="Your session has expired" message="For security, the previous form can no longer be submitted. Return to the application and try again." icon="clock" />
@endsection
