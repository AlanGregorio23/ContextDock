<?php

namespace App\Services;

use App\Jobs\IndexDocument;
use App\Models\{User, Document};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    public function store(User $user, int $projectId, UploadedFile $file): Document
    {
        $project = app(ScopeResolver::class)->project($user,$projectId);
        $extension = strtolower($file->getClientOriginalExtension());
        if (! $file->isValid() || $file->getSize() > 5 * 1024 * 1024 || ! in_array($extension,['txt','md','markdown','php','js','ts','py','java','json','yaml','yml','css','html','sql','sh','go','rs','c','cpp','h'])) {
            throw ValidationException::withMessages(['document'=>'Carica TXT, Markdown o codice UTF-8, massimo 5 MB. PDF e DOCX sono previsti in una fase successiva.']);
        }
        $text = file_get_contents($file->getRealPath());
        if (! mb_check_encoding($text,'UTF-8') || str_contains($text,"\0") || trim($text) === '') {
            throw ValidationException::withMessages(['document'=>'Il documento deve contenere testo UTF-8 non vuoto.']);
        }
        $path = Storage::disk('documents')->putFile($user->id.'/'.$project->id,$file);
        try {
            $document = Document::create(['user_id'=>$user->id,'workspace_id'=>$project->workspace_id,
                'project_id'=>$project->id,'name'=>mb_substr(basename($file->getClientOriginalName()),0,200),'path'=>$path]);
            IndexDocument::dispatch($document->id);
            app(AuditService::class)->record($user,'document.created','document',$document->id);
            return $document;
        } catch (\Throwable $e) { Storage::disk('documents')->delete($path); throw $e; }
    }

    public function delete(User $user, int $id): void
    {
        $document = Document::where('user_id',$user->id)->findOrFail($id);
        $path = $document->path; $document->delete();
        Storage::disk('documents')->delete($path);
        app(AuditService::class)->record($user,'document.deleted','document',$id);
    }
}
