<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendancePeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $start = Carbon::create($this->year, $this->month, 21)->subMonthNoOverflow()->locale('id');
        $end = Carbon::create($this->year, $this->month, 21)->locale('id');

        return ['id' => $this->id, 'month' => $this->month, 'year' => $this->year, 'starts_on' => $this->starts_on?->toDateString(), 'ends_on' => $this->ends_on?->toDateString(), 'label' => 'Periode '.$start->translatedFormat('F').'–'.$end->translatedFormat('F Y'), 'cutoff_label' => '22 '.$start->translatedFormat('F Y').' – 21 '.$end->translatedFormat('F Y'), 'imported_at' => $this->imported_at?->toIso8601String(), 'status' => $this->status->value];
    }
}
