<?php

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request;
use Glueful\Validation\Validator;

/**
 * {{CONTROLLER_NAME}}
 *
 * {{CONTROLLER_DESCRIPTION}}
 *
 * @package Glueful\Controllers
 */
class {{CONTROLLER_NAME}}
{
    /**
     * Display a listing of the resource
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        // TODO: Implement index logic
        return Response::json([
            'message' => 'Index method for {{CONTROLLER_NAME}}',
            'data' => []
        ]);
    }

    /**
     * Show the form for creating a new resource
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        // TODO: Implement create form logic
        return Response::json([
            'message' => 'Create form for {{CONTROLLER_NAME}}'
        ]);
    }

    /**
     * Store a newly created resource in storage
     *
     * @param Request $request
     * @return Response
     */
    public function store(Request $request): Response
    {
        // TODO: Implement validation and storage logic
        $validator = new Validator($request->all(), [
            // Add validation rules here
            // 'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return Response::json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // TODO: Store the resource
        return Response::json([
            'message' => '{{RESOURCE_NAME}} created successfully',
            'data' => $request->all()
        ], 201);
    }

    /**
     * Display the specified resource
     *
     * @param Request $request
     * @param string $id
     * @return Response
     */
    public function show(Request $request, string $id): Response
    {
        // TODO: Implement show logic
        return Response::json([
            'message' => 'Show {{RESOURCE_NAME}} with ID: ' . $id,
            'data' => ['id' => $id]
        ]);
    }

    /**
     * Show the form for editing the specified resource
     *
     * @param Request $request
     * @param string $id
     * @return Response
     */
    public function edit(Request $request, string $id): Response
    {
        // TODO: Implement edit form logic
        return Response::json([
            'message' => 'Edit form for {{RESOURCE_NAME}} with ID: ' . $id,
            'data' => ['id' => $id]
        ]);
    }

    /**
     * Update the specified resource in storage
     *
     * @param Request $request
     * @param string $id
     * @return Response
     */
    public function update(Request $request, string $id): Response
    {
        // TODO: Implement validation and update logic
        $validator = new Validator($request->all(), [
            // Add validation rules here
            // 'name' => 'sometimes|required|string|max:255',
        ]);

        if ($validator->fails()) {
            return Response::json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // TODO: Update the resource
        return Response::json([
            'message' => '{{RESOURCE_NAME}} updated successfully',
            'data' => array_merge(['id' => $id], $request->all())
        ]);
    }

    /**
     * Remove the specified resource from storage
     *
     * @param Request $request
     * @param string $id
     * @return Response
     */
    public function destroy(Request $request, string $id): Response
    {
        // TODO: Implement delete logic
        return Response::json([
            'message' => '{{RESOURCE_NAME}} deleted successfully',
            'data' => ['id' => $id]
        ]);
    }
}