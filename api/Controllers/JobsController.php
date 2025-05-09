<?php

namespace Glueful\Controllers;

use Glueful\Helpers\Request;
use Glueful\Http\Response;
use Glueful\Scheduler\JobScheduler;

/**
 * Jobs Controller
 * 
 * Handles scheduled job management operations:
 * - Listing jobs
 * - Running jobs (all, due, or specific)
 * - Creating new jobs
 * 
 * @package Glueful\Controllers
 */
class JobsController {
    private JobScheduler $scheduler;

    public function __construct() {
        $this->scheduler = JobScheduler::getInstance();
    }

    /**
     * Get all jobs with pagination and filtering
     * 
     * @return mixed HTTP response
     */
    public function getScheduledJobs(): mixed
    {
        try {
            $data = Request::getPostData();
            
            // Build base query
            $jobs = $this->scheduler->getJobs();
           
            if (empty($jobs)) {
                return Response::ok([], 'No jobs found')->send();
            }
           
            return Response::ok($jobs, 'Jobs retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get jobs error: " . $e->getMessage());
            return Response::error(
                'Failed to get jobs: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Run all due jobs
     * 
     * @return mixed HTTP response
     */
    public function runDueJobs(): mixed
    {
        try {
            $this->scheduler->runDueJobs();
            return Response::ok(null, 'Scheduled tasks completed')->send();
        } catch (\Exception $e) {
            error_log("Run due jobs error: " . $e->getMessage());
            return Response::error(
                'Failed to run due jobs: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Run all jobs regardless of schedule
     * 
     * @return mixed HTTP response
     */
    public function runAllJobs(): mixed
    {
        try {
            $this->scheduler->runAllJobs();
            return Response::ok(null, 'All scheduled tasks completed')->send();
        } catch (\Exception $e) {
            error_log("Run all jobs error: " . $e->getMessage());
            return Response::error(
                'Failed to run all jobs: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Run a specific job
     * 
     * @param string $jobName Name of the job to run
     * @return mixed HTTP response
     */
    public function runJob($jobName): mixed
    {
        try {
            $this->scheduler->runJob($jobName);
            return Response::ok(null, 'Scheduled task completed')->send();
        } catch (\Exception $e) {
            error_log("Run job error: " . $e->getMessage());
            return Response::error(
                'Failed to run job: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Create a new scheduled job
     * 
     * @return mixed HTTP response
     */
    public function createJob(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['job_name']) || !isset($data['job_data'])) {
                return Response::error('Job name and data are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $this->scheduler->register($data['job_name'], $data['job_data']);
            return Response::ok(null, 'Scheduled task created')->send();
        } catch (\Exception $e) {
            error_log("Create job error: " . $e->getMessage());
            return Response::error(
                'Failed to create job: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }
}