<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $query = Document::with(['employee', 'type'])->orderByDesc('id');

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('document_name', 'like', "%{$s}%")
                  ->orWhereHas('employee', function ($eq) use ($s) {
                      $eq->where('full_name', 'like', "%{$s}%");
                  });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100, 200], true) ? (int) $request->input('per_page', 25) : 25;
        $documents = $query->paginate($perPage)->withQueryString();
        return view('documents.index', compact('documents'));
    }

    public function store(Request $request, Employee $employee)
    {
        $request->validate([
            'document_name' => ['required', 'string', 'max:200'],
            'document_type_id' => ['nullable', new \App\Rules\VisibleModel(\App\Models\DocumentType::class)],
            'expiry_date' => ['nullable', 'date'],
            'document_file' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx'],
        ], [
            'document_name.required' => 'اسم المسوغ مطلوب.',
            'document_file.max' => 'الحد الأقصى لحجم الملف هو 10 ميجابايت.',
            'document_file.mimes' => 'الملفات المدعومة هي الصور وملفات PDF ومستندات Word.',
        ]);

        $docId = $request->input('document_id');
        $document = $docId ? Document::where('employee_id', $employee->id)->find($docId) : null;

        if (!$document) {
            $document = new Document();
            $document->employee_id = $employee->id;
            $document->status = 'مطلوب';
        }

        $document->document_name = trim($request->document_name);
        $document->document_type_id = $request->input('document_type_id') ?: null;
        $document->expiry_date = $request->input('expiry_date') ?: null;

        if ($request->hasFile('document_file')) {
            $file = $request->file('document_file');
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $mimeType = $file->getClientMimeType();
            $fileSize = $file->getSize();

            $path = $file->store("documents/{$employee->id}", 'local');

            $document->file_name = $originalName;
            $document->file_path = $path;
            $document->mime_type = $mimeType;
            $document->file_size = $fileSize;
            $document->document_type = strtoupper($extension);
            $document->status = 'مكتمل';
            $document->uploaded_by = auth()->id();
            $document->uploaded_at = now();
        }

        $document->save();
        ActivityLog::record('document_upload', "تم حفظ مسوغ التعيين ({$document->document_name}) للموظف {$employee->full_name}.", $document);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('تم حفظ المسوغ بنجاح'),
                'document' => $document,
            ]);
        }

        return redirect()->route('employees.show', [$employee, 'tab' => 'documentsProfile'])->with('toast', __('تم حفظ المسوغ بنجاح'));
    }

    public function replace(Request $request, Document $document)
    {
        $request->validate([
            'document_file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx'],
        ]);

        if ($document->file_path && Storage::disk($document->storageDisk())->exists($document->file_path)) {
            Storage::disk($document->storageDisk())->delete($document->file_path);
        }

        $file = $request->file('document_file');
        $path = $file->store("documents/{$document->employee_id}", 'local');

        $document->file_name = $file->getClientOriginalName();
        $document->file_path = $path;
        $document->mime_type = $file->getClientMimeType();
        $document->file_size = $file->getSize();
        $document->document_type = strtoupper($file->getClientOriginalExtension());
        $document->status = 'مكتمل';
        $document->uploaded_by = auth()->id();
        $document->uploaded_at = now();
        $document->save();

        ActivityLog::record('document_replace', "تم استبدال ملف المسوغ ({$document->document_name}) للموظف {$document->employee?->full_name}.", $document);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('تم استبدال المسوغ بنجاح'),
                'document' => $document,
            ]);
        }

        return back()->with('toast', __('تم استبدال المسوغ بنجاح'));
    }

    public function preview(Document $document)
    {
        if (!$document->file_path || !Storage::disk($document->storageDisk())->exists($document->file_path)) {
            abort(404, __('الملف غير موجود.'));
        }

        return Storage::disk($document->storageDisk())->response($document->file_path, $document->file_name);
    }

    public function destroy(Document $document, Request $request)
    {
        if ($document->file_path && Storage::disk($document->storageDisk())->exists($document->file_path)) {
            Storage::disk($document->storageDisk())->delete($document->file_path);
        }

        // If it's a fixed required document template, reset it to 'مطلوب' instead of dropping the row,
        // unless requested specifically to delete row.
        $deleteRow = $request->boolean('force_delete', false);
        $emp = $document->employee;
        $docName = $document->document_name;

        if ($deleteRow) {
            $document->delete();
        } else {
            $document->file_path = null;
            $document->file_name = null;
            $document->file_size = null;
            $document->mime_type = null;
            $document->document_type = null;
            $document->status = 'مطلوب';
            $document->uploaded_at = null;
            $document->save();
        }

        ActivityLog::record('document_delete', "تم حذف ملف المسوغ ({$docName}) للموظف {$emp?->full_name}.");

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('تم حذف المسوغ'),
            ]);
        }

        return back()->with('toast', __('تم حذف المسوغ'));
    }
}
