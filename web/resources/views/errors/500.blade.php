@extends('layouts.app')
@section('title', 'Application Error')
@section('content')
    <x-error-page code="500" title="Something went wrong" message="The application could not complete this request. No technical details have been exposed. Please try again or contact support if the problem continues." icon="warning" />
@endsection
