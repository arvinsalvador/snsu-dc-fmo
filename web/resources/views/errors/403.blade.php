@extends('layouts.app')
@section('title', 'Access Denied')
@section('content')
    <x-error-page code="403" title="You do not have access to this page" message="Your account does not have the required permission. Return to an authorized area or contact an administrator if you believe this is unexpected." icon="lock" />
@endsection
