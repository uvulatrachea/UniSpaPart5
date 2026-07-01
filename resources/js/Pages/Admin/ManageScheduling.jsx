import { Head, router, useForm, usePage } from "@inertiajs/react";
import { useMemo, useState } from "react";
import AdminShell from "./Partials/AdminShell";

export default function ManageScheduling({
  kpis = {},
  filters = {},
  staffDirectory = { general: [], student: [] },
  staffOptions = [],
  rooms = [],
  schedules = [],
  monthSchedules = [],
  assignedBookings = [],
  selectedDateBookings = [],
  monthAppointmentCounts = [],
  pendingQrBookings = [],
  pendingStudentAvailability = [],
}) {
  const { flash = {} } = usePage().props;

  const [viewMode, setViewMode] = useState("appointments");
  const [selectedDate, setSelectedDate] = useState(filters.selected_date || toDateInput(new Date()));
  const [monthCursor, setMonthCursor] = useState(new Date((filters.selected_date || toDateInput(new Date())) + "T00:00:00"));
  const [weekStart, setWeekStart] = useState(filters.week_start || startOfWeekISO(selectedDate));

  const [assignModal, setAssignModal] = useState({ open: false, booking: null });
  const [shiftModal, setShiftModal] = useState({ open: false, date: selectedDate, staffId: "" });

  const [availabilitySelection, setAvailabilitySelection] = useState([]);
  const [rejectNotes, setRejectNotes] = useState("");

  const assignForm = useForm({ staff_id: "", room_id: "" });
  const shiftForm = useForm({
    staff_id: "",
    schedule_date: selectedDate,
    start_time: "10:00",
    end_time: "18:00",
    created_by: "admin",
    room_id: "",
  });

  const monthStatsByDate = useMemo(() => {
    const map = {};
    (monthAppointmentCounts || []).forEach((row) => {
      map[row.slot_date] = {
        total: Number(row.total_count || 0),
        qrPending: Number(row.qr_pending_count || 0),
      };
    });
    return map;
  }, [monthAppointmentCounts]);

  const activeStaff = useMemo(
    () => (staffOptions || []).filter((s) => String(s.work_status || "").toLowerCase() === "active"),
    [staffOptions]
  );

  const schedulesByDate = useMemo(() => {
    const map = {};
    (monthSchedules || []).forEach((s) => {
      const d = String(s.schedule_date);
      map[d] = map[d] || [];
      map[d].push(s);
    });
    return map;
  }, [monthSchedules]);

  const selectedDateEvents = useMemo(() => {
    return (selectedDateBookings || []).slice().sort((a, b) => toMinutes(a.start_time) - toMinutes(b.start_time));
  }, [selectedDateBookings]);

  const weekDays = useMemo(() => {
    const start = new Date(weekStart + "T00:00:00");
    return Array.from({ length: 7 }, (_, i) => {
      const d = new Date(start);
      d.setDate(start.getDate() + i);
      return toDateInput(d);
    });
  }, [weekStart]);

  const reloadScheduling = (payload) => {
    router.get(route("admin.scheduling"), payload, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const pickDate = (dateStr) => {
    setSelectedDate(dateStr);
    setMonthCursor(new Date(dateStr + "T00:00:00"));
    const nextWeek = startOfWeekISO(dateStr);
    setWeekStart(nextWeek);
    reloadScheduling({ selected_date: dateStr, week_start: nextWeek });
  };

  const moveMonth = (offset) => {
    const next = new Date(monthCursor);
    next.setMonth(next.getMonth() + offset);
    setMonthCursor(next);
    const focusDate = toDateInput(new Date(next.getFullYear(), next.getMonth(), 1));
    pickDate(focusDate);
  };

  const moveWeek = (offset) => {
    const current = new Date(weekStart + "T00:00:00");
    current.setDate(current.getDate() + offset * 7);
    const nextWeek = toDateInput(current);
    setWeekStart(nextWeek);
    reloadScheduling({ selected_date: selectedDate, week_start: nextWeek });
  };

  const openAssign = (booking) => {
    const staffCandidates = getAvailableStaffForBooking(booking, activeStaff, schedulesByDate, selectedDateEvents);
    const roomCandidates = getAvailableRoomsForBooking(booking, rooms, selectedDateEvents);
    assignForm.setData({
      staff_id: staffCandidates[0]?.staff_id ? String(staffCandidates[0].staff_id) : "",
      room_id: roomCandidates[0]?.room_id ? String(roomCandidates[0].room_id) : "",
    });
    setAssignModal({ open: true, booking });
  };

  const submitAssign = (e) => {
    e.preventDefault();
    if (!assignModal.booking) return;
    assignForm.post(route("admin.scheduling.bookings.confirm", assignModal.booking.booking_id), {
      preserveScroll: true,
      onSuccess: () => setAssignModal({ open: false, booking: null }),
    });
  };

  const openShift = (date, staffId = "") => {
    shiftForm.setData({
      ...shiftForm.data,
      schedule_date: date,
      staff_id: staffId ? String(staffId) : "",
      start_time: "10:00",
      end_time: "18:00",
      created_by: "admin",
      room_id: "",
    });
    setShiftModal({ open: true, date, staffId: staffId ? String(staffId) : "" });
  };

  const submitShift = (e) => {
    e.preventDefault();
    shiftForm.post(route("admin.scheduling.shifts.store"), {
      preserveScroll: true,
      onSuccess: () => setShiftModal({ open: false, date: selectedDate, staffId: "" }),
    });
  };

  const publishWeek = () => {
    router.post(
      route("admin.scheduling.publish"),
      { week_start: weekStart },
      { preserveScroll: true }
    );
  };

  const toggleAvailability = (scheduleId) => {
    setAvailabilitySelection((prev) => {
      const id = String(scheduleId);
      if (prev.includes(id)) return prev.filter((x) => x !== id);
      return [...prev, id];
    });
  };

  const bulkApproveAvailability = (action) => {
    if (!availabilitySelection.length) {
      alert("Please select at least 1 availability row.");
      return;
    }

    if (action === "reject") {
      const notes = (rejectNotes || "").trim();
      if (!notes) {
        alert("Please enter a reject reason/notes first.");
        return;
      }
      if (notes.length > 500) {
        alert("Notes must be 500 characters or less.");
        return;
      }
    }

    router.post(
      route("admin.scheduling.student_availability.approve"),
      {
        schedule_ids: availabilitySelection.map((x) => Number(x)),
        action,
        notes: action === "reject" ? (rejectNotes || "").trim() : null,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setAvailabilitySelection([]);
          if (action === "reject") setRejectNotes("");
        },
      }
    );
  };

  const currentMonthTitle = monthCursor.toLocaleDateString("en-MY", { month: "long", year: "numeric" });
  const monthGrid = buildMonthGrid(monthCursor);
  const currentMonthIndex = monthCursor.getMonth();
  const currentYearValue = monthCursor.getFullYear();

  const yearOptions = useMemo(() => {
    const start = Math.min(2026, currentYearValue - 10);
    const end = currentYearValue + 20;
    return Array.from({ length: end - start + 1 }, (_, i) => start + i);
  }, [currentYearValue]);

  const monthNames = [
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
  ];

  const jumpToMonthYear = (nextMonth, nextYear) => {
    const next = new Date(Number(nextYear), Number(nextMonth), 1);
    setMonthCursor(next);
    pickDate(toDateInput(next));
  };

  const staffCalendarByDate = useMemo(() => {
    const map = {};
    (monthSchedules || []).forEach((s) => {
      const d = String(s.schedule_date);
      if (!map[d]) {
        map[d] = {
          total: 0,
          general: 0,
          student: 0,
          names: [],
          rows: [],
        };
      }
      map[d].total += 1;
      map[d].rows.push(s);
      if (String(s.staff_type) === "student") map[d].student += 1;
      else map[d].general += 1;
      if (!map[d].names.includes(s.staff_name)) map[d].names.push(s.staff_name);
    });
    return map;
  }, [monthSchedules]);

  const selectedDateStaffShifts = useMemo(
    () => (staffCalendarByDate[selectedDate]?.rows || []).slice().sort((a, b) => toMinutes(a.start_time) - toMinutes(b.start_time)),
    [staffCalendarByDate, selectedDate]
  );

  const staffSummaryList = useMemo(() => {
    const byStaff = new Map();
    (monthSchedules || []).forEach((s) => {
      const key = String(s.staff_id);
      if (!byStaff.has(key)) {
        byStaff.set(key, {
          staff_id: s.staff_id,
          staff_name: s.staff_name,
          staff_type: s.staff_type,
          total_shifts: 0,
          days: new Set(),
        });
      }
      const row = byStaff.get(key);
      row.total_shifts += 1;
      row.days.add(String(s.schedule_date));
    });

    return Array.from(byStaff.values())
      .map((r) => ({ ...r, unique_days: r.days.size }))
      .sort((a, b) => b.total_shifts - a.total_shifts || String(a.staff_name).localeCompare(String(b.staff_name)));
  }, [monthSchedules]);

  const selectedStaffOptions = assignModal.booking
    ? getAvailableStaffForBooking(assignModal.booking, activeStaff, schedulesByDate, selectedDateEvents)
    : [];
  const selectedRoomOptions = assignModal.booking
    ? getAvailableRoomsForBooking(assignModal.booking, rooms, selectedDateEvents)
    : [];

  return (
    <>
      <Head title="Manage Scheduling" />
      <AdminShell
        title="Manage Scheduling"
        subtitle="Calendar-first appointment assignment + staff scheduling (responsive)."
      >
        {(flash.success || flash.error) && (
          <div className={`mb-4 rounded-xl border px-4 py-3 text-sm font-semibold ${flash.success ? "border-emerald-200 bg-emerald-50 text-emerald-700" : "border-rose-200 bg-rose-50 text-rose-700"}`}>
            {flash.success || flash.error}
          </div>
        )}

        <section className="grid grid-cols-2 gap-3 lg:grid-cols-5">
          <Kpi label="Today Schedules" value={kpis.todaySchedules} />
          <Kpi label="Today Slots" value={kpis.todaySlots} />
          <Kpi label="Available Slots" value={kpis.availableSlots} />
          <Kpi label="Pending QR Proof" value={kpis.pendingQrProofs} />
          <Kpi label="Treatment Rooms" value={kpis.totalRooms} />
        </section>

        <section className="mt-5 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="inline-flex rounded-xl border border-slate-200 p-1">
              <button
                type="button"
                onClick={() => setViewMode("appointments")}
                className={`rounded-lg px-3 py-2 text-sm font-bold ${viewMode === "appointments" ? "bg-slate-900 text-white" : "text-slate-600"}`}
              >
                Appointment Calendar
              </button>
              <button
                type="button"
                onClick={() => setViewMode("staff")}
                className={`rounded-lg px-3 py-2 text-sm font-bold ${viewMode === "staff" ? "bg-slate-900 text-white" : "text-slate-600"}`}
              >
                Staff Scheduling Calendar
              </button>
            </div>

            <div className="flex flex-wrap gap-2">
              <button type="button" onClick={publishWeek} className="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700">
                Publish & Notify Staff
              </button>
              <button type="button" onClick={() => openShift(selectedDate)} className="rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-2 text-sm font-bold text-indigo-700">
                + Add Shift / Availability
              </button>
            </div>
          </div>

          {viewMode === "appointments" ? (
            <>
              <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <button className="rounded-md border border-slate-300 bg-white px-3 py-1" onClick={() => moveMonth(-1)}>
                      <i className="fas fa-chevron-left" />
                    </button>
                    <h3 className="text-base font-extrabold text-slate-700">{currentMonthTitle}</h3>
                    <button className="rounded-md border border-slate-300 bg-white px-3 py-1" onClick={() => moveMonth(1)}>
                      <i className="fas fa-chevron-right" />
                    </button>
                  </div>

                  <div className="flex flex-wrap items-center gap-2">
                    <select
                      value={currentMonthIndex}
                      onChange={(e) => jumpToMonthYear(e.target.value, currentYearValue)}
                      className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm"
                    >
                      {monthNames.map((m, idx) => (
                        <option key={m} value={idx}>
                          {m}
                        </option>
                      ))}
                    </select>

                    <select
                      value={currentYearValue}
                      onChange={(e) => jumpToMonthYear(currentMonthIndex, e.target.value)}
                      className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm"
                    >
                      {yearOptions.map((y) => (
                        <option key={y} value={y}>
                          {y}
                        </option>
                      ))}
                    </select>

                    <button
                      type="button"
                      onClick={() => pickDate(toDateInput(new Date()))}
                      className="rounded-md border border-slate-300 bg-white px-3 py-1 text-sm font-semibold"
                    >
                      Today
                    </button>
                  </div>
                </div>

                <div className="grid grid-cols-7 gap-2 text-center text-xs font-bold uppercase tracking-wide text-slate-500">
                  {["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((d) => (
                    <div key={d}>{d}</div>
                  ))}
                </div>

                <div className="mt-2 grid grid-cols-7 gap-2">
                  {monthGrid.map((day, idx) => {
                    const stats = monthStatsByDate[day.date] || { total: 0, qrPending: 0 };
                    const isSelected = day.date === selectedDate;
                    return (
                      <button
                        key={`${day.date}-${idx}`}
                        type="button"
                        onClick={() => pickDate(day.date)}
                        className={`min-h-[88px] rounded-xl border p-2 text-left transition ${isSelected ? "border-slate-900 bg-slate-900 text-white" : day.isCurrentMonth ? "border-slate-200 bg-white hover:border-slate-300" : "border-slate-100 bg-slate-100 text-slate-400"}`}
                      >
                        <div className="text-sm font-bold">{day.day}</div>
                        <div className="mt-2 space-y-1 text-[11px]">
                          <div className={`${isSelected ? "text-white/90" : "text-slate-600"}`}>{stats.total} appt.</div>
                          {stats.qrPending > 0 && (
                            <div className={`inline-flex rounded-full px-2 py-0.5 font-bold ${isSelected ? "bg-amber-200 text-amber-900" : "bg-amber-100 text-amber-700"}`}>
                              {stats.qrPending} pending QR
                            </div>
                          )}
                        </div>
                      </button>
                    );
                  })}
                </div>
              </div>

              <div className="mt-4 rounded-2xl border border-slate-200">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
                  <h3 className="text-sm font-extrabold text-slate-700">Appointments on {prettyDate(selectedDate)}</h3>
                  <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{selectedDateEvents.length} bookings</span>
                </div>

                <div className="overflow-x-auto">
                  <table className="min-w-full text-sm">
                    <thead className="bg-slate-50 text-left text-slate-500">
                      <tr>
                        <th className="px-3 py-2">Time</th>
                        <th className="px-3 py-2">Booking</th>
                        <th className="px-3 py-2">Payment Proof</th>
                        <th className="px-3 py-2">Staff</th>
                        <th className="px-3 py-2">Room</th>
                        <th className="px-3 py-2">Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selectedDateEvents.length ? (
                        selectedDateEvents.map((b) => (
                          <tr key={b.booking_id} className="border-t border-slate-100">
                            <td className="px-3 py-3 font-semibold text-slate-700">
                              {shortTime(b.start_time)} - {shortTime(b.end_time)}
                            </td>
                            <td className="px-3 py-3">
                              <div className="font-bold text-slate-700">#{b.booking_id} • {b.customer_name || "Customer"}</div>
                              <div className="text-xs text-slate-500">{b.service_name || "Service"}</div>
                            </td>
                            <td className="px-3 py-3">
                              {b.proof_url ? (
                                <a href={b.proof_url} target="_blank" rel="noreferrer" className="rounded-full bg-sky-100 px-3 py-1 text-xs font-bold text-sky-700">
                                  View Proof
                                </a>
                              ) : (
                                <span className="text-xs text-slate-400">No proof</span>
                              )}
                            </td>
                            <td className="px-3 py-3">{b.assigned_staff_name || "Unassigned"}</td>
                            <td className="px-3 py-3">{b.room_name || b.room_type || "Unassigned"}</td>
                            <td className="px-3 py-3">
                              <button
                                type="button"
                                onClick={() => openAssign(b)}
                                className="rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700"
                              >
                                Assign Staff + Room
                              </button>
                            </td>
                          </tr>
                        ))
                      ) : (
                        <tr>
                          <td colSpan={6} className="px-3 py-8 text-center text-slate-500">
                            No appointments found for this date.
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            </>
          ) : (
            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[300px,1fr]">
              <aside className="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <div className="mb-3 flex items-center justify-between">
                  <h3 className="text-sm font-extrabold text-slate-700">Staff Directory</h3>
                  <button type="button" className="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-bold" onClick={() => openShift(selectedDate)}>
                    + Shift
                  </button>
                </div>

                <StaffGroup
                  title="Full-Time Staff"
                  items={staffDirectory.general || []}
                  onQuickAssign={(id) => openShift(selectedDate, id)}
                />
                <StaffGroup
                  title="Part-Time Staff"
                  items={staffDirectory.student || []}
                  onQuickAssign={(id) => openShift(selectedDate, id)}
                />
              </aside>

              <div className="rounded-2xl border border-slate-200 bg-white p-3">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <button className="rounded-md border border-slate-300 px-3 py-1" onClick={() => moveMonth(-1)}>
                      <i className="fas fa-chevron-left" />
                    </button>
                    <h3 className="text-base font-extrabold text-slate-700">Staff Calendar • {currentMonthTitle}</h3>
                    <button className="rounded-md border border-slate-300 px-3 py-1" onClick={() => moveMonth(1)}>
                      <i className="fas fa-chevron-right" />
                    </button>
                  </div>

                  <div className="flex flex-wrap items-center gap-2">
                    <select
                      value={currentMonthIndex}
                      onChange={(e) => jumpToMonthYear(e.target.value, currentYearValue)}
                      className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm"
                    >
                      {monthNames.map((m, idx) => (
                        <option key={`staff-${m}`} value={idx}>
                          {m}
                        </option>
                      ))}
                    </select>

                    <select
                      value={currentYearValue}
                      onChange={(e) => jumpToMonthYear(currentMonthIndex, e.target.value)}
                      className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm"
                    >
                      {yearOptions.map((y) => (
                        <option key={`staff-y-${y}`} value={y}>
                          {y}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                <div className="grid grid-cols-7 gap-2 text-center text-xs font-bold uppercase tracking-wide text-slate-500">
                  {["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((d) => (
                    <div key={`staff-day-${d}`}>{d}</div>
                  ))}
                </div>

                <div className="mt-2 grid grid-cols-7 gap-2">
                  {monthGrid.map((day, idx) => {
                    const info = staffCalendarByDate[day.date] || { total: 0, names: [], general: 0, student: 0 };
                    const isSelected = day.date === selectedDate;
                    return (
                      <button
                        key={`staff-cell-${day.date}-${idx}`}
                        type="button"
                        onClick={() => pickDate(day.date)}
                        className={`min-h-[108px] rounded-xl border p-2 text-left ${isSelected ? "border-slate-900 bg-slate-900 text-white" : day.isCurrentMonth ? "border-slate-200 bg-white hover:border-slate-300" : "border-slate-100 bg-slate-100 text-slate-400"}`}
                      >
                        <div className="flex items-center justify-between">
                          <span className="text-sm font-bold">{day.day}</span>
                          <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${isSelected ? "bg-white/20 text-white" : "bg-slate-100 text-slate-600"}`}>
                            {info.total}
                          </span>
                        </div>

                        <div className="mt-1 space-y-1 text-[10px]">
                          <div className={`${isSelected ? "text-white/90" : "text-slate-500"}`}>FT: {info.general} • PT: {info.student}</div>
                          {(info.names || []).slice(0, 2).map((n) => (
                            <div key={`${day.date}-${n}`} className={`truncate rounded px-1 py-0.5 ${isSelected ? "bg-white/20" : "bg-indigo-50 text-indigo-700"}`}>
                              {n}
                            </div>
                          ))}
                          {info.names?.length > 2 && (
                            <div className={`${isSelected ? "text-white/80" : "text-slate-500"}`}>+{info.names.length - 2} more</div>
                          )}
                        </div>
                      </button>
                    );
                  })}
                </div>

                <div className="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-2">
                  <div className="rounded-xl border border-slate-200">
                    <div className="border-b border-slate-200 px-3 py-2 text-sm font-extrabold text-slate-700">
                      Shifts on {prettyDate(selectedDate)}
                    </div>
                    <div className="max-h-[320px] overflow-auto p-2">
                      {selectedDateStaffShifts.length ? (
                        <div className="space-y-2">
                          {selectedDateStaffShifts.map((s) => (
                            <div key={`daily-shift-${s.schedule_id}`} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                              <p className="text-xs font-bold text-slate-700">{shortTime(s.start_time)} - {shortTime(s.end_time)}</p>
                              <p className="text-sm font-semibold text-slate-700">{s.staff_name}</p>
                              <p className="text-[11px] text-slate-500">{staffTypeLabel(s.staff_type)} • {humanize(s.created_by)}</p>
                            </div>
                          ))}
                        </div>
                      ) : (
                        <p className="px-1 py-3 text-sm text-slate-500">No shifts on this day.</p>
                      )}
                    </div>
                  </div>

                  <div className="rounded-xl border border-slate-200">
                    <div className="border-b border-slate-200 px-3 py-2 text-sm font-extrabold text-slate-700">
                      Staff Shift Summary ({currentMonthTitle})
                    </div>
                    <div className="max-h-[320px] overflow-auto p-2">
                      {staffSummaryList.length ? (
                        <div className="space-y-2">
                          {staffSummaryList.map((s) => (
                            <div key={`sum-${s.staff_id}`} className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                              <div>
                                <p className="text-sm font-semibold text-slate-700">{s.staff_name}</p>
                                <p className="text-[11px] text-slate-500">{staffTypeLabel(s.staff_type)} • {s.unique_days} days</p>
                              </div>
                              <div className="flex items-center gap-2">
                                <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700">{s.total_shifts} shifts</span>
                                <button
                                  type="button"
                                  onClick={() => openShift(selectedDate, s.staff_id)}
                                  className="rounded border border-slate-300 px-2 py-1 text-[11px] font-bold"
                                >
                                  Add
                                </button>
                              </div>
                            </div>
                          ))}
                        </div>
                      ) : (
                        <p className="px-1 py-3 text-sm text-slate-500">No shift summary for this month yet.</p>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}

          <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <h3 className="mb-2 text-sm font-extrabold text-slate-700">Incoming Appointments (Pending QR)</h3>
            <div className="space-y-2">
              {(pendingQrBookings || []).slice(0, 6).map((b) => (
                <div key={b.booking_id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                  <div>
                    <p className="font-bold text-slate-700">#{b.booking_id} • {b.customer_name}</p>
                    <p className="text-xs text-slate-500">{prettyDate(b.slot_date)} • {shortTime(b.start_time)} - {shortTime(b.end_time)} • {b.service_name}</p>
                  </div>
                  <button type="button" onClick={() => openAssign(b)} className="rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700">
                    Review & Confirm
                  </button>
                </div>
              ))}
              {!pendingQrBookings?.length && <p className="text-sm text-slate-500">No pending QR proofs right now.</p>}
            </div>
          </div>

          <div className="mt-4 rounded-2xl border border-slate-200 bg-white p-3">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-sm font-extrabold text-slate-700">Part-Time Staff Availability Requests (Pending Approval)</h3>
              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  onClick={() => bulkApproveAvailability("approve")}
                  className="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700"
                >
                  Approve Selected
                </button>
                <button
                  type="button"
                  onClick={() => bulkApproveAvailability("reject")}
                  className="rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700"
                >
                  Reject Selected
                </button>
              </div>
            </div>

            <div className="mb-3 rounded-xl border border-rose-200 bg-rose-50 p-3">
              <p className="text-xs font-extrabold uppercase tracking-wide text-rose-700">Reject Notes (required when rejecting)</p>
              <textarea
                value={rejectNotes}
                onChange={(e) => setRejectNotes(e.target.value)}
                rows={3}
                maxLength={500}
                placeholder="Example: Please adjust to only 2 days this week due to exam week; avoid overlapping lunch period 1PM–2PM."
                className="mt-2 w-full rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm"
              />
              <p className="mt-1 text-[11px] font-semibold text-rose-700/80">
                {Math.max(0, 500 - String(rejectNotes || "").length)} characters left.
              </p>
            </div>

            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead className="bg-slate-50 text-left text-slate-500">
                  <tr>
                    <th className="px-3 py-2">Select</th>
                    <th className="px-3 py-2">Part-Time Staff</th>
                    <th className="px-3 py-2">Date</th>
                    <th className="px-3 py-2">Time</th>
                    <th className="px-3 py-2">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {(pendingStudentAvailability || []).length ? (
                    (pendingStudentAvailability || []).map((row) => (
                      <tr key={`avail-${row.schedule_id}`} className="border-t border-slate-100">
                        <td className="px-3 py-3">
                          <input
                            type="checkbox"
                            checked={availabilitySelection.includes(String(row.schedule_id))}
                            onChange={() => toggleAvailability(row.schedule_id)}
                          />
                        </td>
                        <td className="px-3 py-3 font-semibold text-slate-700">{row.staff_name}</td>
                        <td className="px-3 py-3">{prettyDate(row.schedule_date)}</td>
                        <td className="px-3 py-3">{shortTime(row.start_time)} - {shortTime(row.end_time)}</td>
                        <td className="px-3 py-3">
                          <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-bold text-amber-700">Pending</span>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={5} className="px-3 py-6 text-center text-slate-500">
                        No pending student availability requests.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            <p className="mt-2 text-[11px] font-semibold text-slate-500">
              Approved availability will immediately become bookable (subject to staff + room collision checks).
            </p>
          </div>
        </section>

        {assignModal.open && assignModal.booking && (
          <Modal title={`Assign Booking #${assignModal.booking.booking_id}`} onClose={() => setAssignModal({ open: false, booking: null })}>
            <form onSubmit={submitAssign} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 sm:col-span-2">
                <p className="font-bold text-slate-700">{assignModal.booking.customer_name} • {assignModal.booking.service_name}</p>
                <p className="text-xs text-slate-500">{prettyDate(assignModal.booking.slot_date)} • {shortTime(assignModal.booking.start_time)} - {shortTime(assignModal.booking.end_time)}</p>
                <p className="mt-1 text-xs text-slate-500">Special request: {assignModal.booking.special_requests || "-"}</p>
              </div>

              <label className="text-sm">
                <span className="mb-1 block font-medium text-slate-700">Available Staff</span>
                <select
                  value={assignForm.data.staff_id}
                  onChange={(e) => assignForm.setData("staff_id", e.target.value)}
                  className="w-full rounded-lg border border-slate-200 px-3 py-2"
                  required
                >
                  <option value="">Select staff</option>
                  {selectedStaffOptions.map((s) => (
                    <option key={s.staff_id} value={s.staff_id}>
                      {s.name} ({staffTypeLabel(s.staff_type)})
                    </option>
                  ))}
                </select>
                {assignForm.errors.staff_id && <p className="text-xs text-rose-600">{assignForm.errors.staff_id}</p>}
              </label>

              <label className="text-sm">
                <span className="mb-1 block font-medium text-slate-700">Available Room (service-matched)</span>
                <select
                  value={assignForm.data.room_id}
                  onChange={(e) => assignForm.setData("room_id", e.target.value)}
                  className="w-full rounded-lg border border-slate-200 px-3 py-2"
                >
                  <option value="">Select room</option>
                  {selectedRoomOptions.map((r) => (
                    <option key={r.room_id} value={r.room_id}>
                      {r.room_label || r.room_type} ({humanize(r.gender || "unisex")})
                    </option>
                  ))}
                </select>
                {assignForm.errors.room_id && <p className="text-xs text-rose-600">{assignForm.errors.room_id}</p>}
              </label>

              <div className="sm:col-span-2 rounded-xl border border-slate-200 p-3">
                <p className="mb-2 text-sm font-bold text-slate-700">Payment Proof</p>
                {assignModal.booking.proof_url ? (
                  <a href={assignModal.booking.proof_url} target="_blank" rel="noreferrer" className="inline-flex rounded-lg border border-sky-300 bg-sky-50 px-3 py-1.5 text-sm font-bold text-sky-700">
                    View Uploaded Proof Image
                  </a>
                ) : (
                  <p className="text-sm text-slate-500">No proof uploaded for this booking.</p>
                )}
              </div>

              <div className="flex justify-end gap-2 sm:col-span-2">
                <button type="button" onClick={() => setAssignModal({ open: false, booking: null })} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">
                  Cancel
                </button>
                <button disabled={assignForm.processing} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">
                  Confirm & Send Email
                </button>
              </div>
            </form>
          </Modal>
        )}

        {shiftModal.open && (
          <Modal title="Add Shift / Availability" onClose={() => setShiftModal({ open: false, date: selectedDate, staffId: "" })}>
            <form onSubmit={submitShift} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <label className="text-sm sm:col-span-2">
                <span className="mb-1 block font-medium text-slate-700">Staff</span>
                <select
                  value={shiftForm.data.staff_id}
                  onChange={(e) => shiftForm.setData("staff_id", e.target.value)}
                  className="w-full rounded-lg border border-slate-200 px-3 py-2"
                  required
                >
                  <option value="">Select staff</option>
                  {activeStaff.map((s) => (
                    <option key={s.staff_id} value={s.staff_id}>{s.name} ({staffTypeLabel(s.staff_type)})</option>
                  ))}
                </select>
                {shiftForm.errors.staff_id && <p className="text-xs text-rose-600">{shiftForm.errors.staff_id}</p>}
              </label>

              <Field
                label="Date"
                type="date"
                value={shiftForm.data.schedule_date}
                onChange={(v) => shiftForm.setData("schedule_date", v)}
                error={shiftForm.errors.schedule_date}
              />
              <Field
                label="Start Time"
                type="time"
                value={shiftForm.data.start_time}
                onChange={(v) => shiftForm.setData("start_time", v)}
                error={shiftForm.errors.start_time}
              />
              <Field
                label="End Time"
                type="time"
                value={shiftForm.data.end_time}
                onChange={(v) => shiftForm.setData("end_time", v)}
                error={shiftForm.errors.end_time}
              />

              <label className="text-sm sm:col-span-2">
                <span className="mb-1 block font-medium text-slate-700">Treatment Room (optional)</span>
                <select
                  value={shiftForm.data.room_id}
                  onChange={(e) => shiftForm.setData("room_id", e.target.value)}
                  className="w-full rounded-lg border border-slate-200 px-3 py-2"
                >
                  <option value="">No room assigned</option>
                  {(rooms || []).map((r) => (
                    <option key={r.room_id} value={r.room_id}>
                      {r.room_label || r.room_type} (Room {r.room_id})
                    </option>
                  ))}
                </select>
                {shiftForm.errors.room_id && <p className="text-xs text-rose-600">{shiftForm.errors.room_id}</p>}
              </label>

              <p className="text-[11px] font-semibold text-slate-500 sm:col-span-2">Business hours: 10:00 – 18:00</p>

              <div className="flex justify-end gap-2 sm:col-span-2">
                <button type="button" onClick={() => setShiftModal({ open: false, date: selectedDate, staffId: "" })} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">
                  Cancel
                </button>
                <button disabled={shiftForm.processing} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">
                  Save Shift
                </button>
              </div>
            </form>
          </Modal>
        )}
      </AdminShell>
    </>
  );
}

function StaffGroup({ title, items = [], onQuickAssign }) {
  return (
    <div className="mb-3 rounded-xl border border-slate-200 bg-white p-2">
      <h4 className="mb-1 px-1 text-xs font-extrabold uppercase tracking-wide text-slate-500">{title}</h4>
      <div className="space-y-1">
        {items.map((s) => (
          <div key={s.staff_id} className="flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-2 py-1.5">
            <div>
              <p className="text-sm font-semibold text-slate-700">{s.name}</p>
              <p className="text-[11px] text-slate-500">{humanize(s.role || s.staff_type)}</p>
            </div>
            <button type="button" onClick={() => onQuickAssign(s.staff_id)} className="rounded border border-slate-300 px-2 py-0.5 text-[11px] font-bold">
              +
            </button>
          </div>
        ))}
        {!items.length && <p className="px-1 py-2 text-xs text-slate-400">No staff</p>}
      </div>
    </div>
  );
}

function Modal({ title, children, onClose }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4">
      <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
        <div className="mb-3 flex items-center justify-between">
          <h3 className="text-lg font-extrabold text-slate-800">{title}</h3>
          <button type="button" onClick={onClose} className="rounded-md border border-slate-300 px-3 py-1 text-xs font-bold text-slate-700">Close</button>
        </div>
        {children}
      </div>
    </div>
  );
}

function Kpi({ label, value }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
      <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-extrabold text-slate-800">{Number(value || 0)}</p>
    </div>
  );
}

function Field({ label, value, onChange, error, type = "text" }) {
  return (
    <label className="text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <input type={type} value={value ?? ""} onChange={(e) => onChange(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2" />
      {error && <span className="text-xs text-rose-600">{error}</span>}
    </label>
  );
}

function getAvailableStaffForBooking(booking, activeStaff, schedulesByDate, selectedDateBookings) {
  const date = String(booking.slot_date);
  const start = toMinutes(booking.start_time);
  const end = toMinutes(booking.end_time);
  const daySchedules = schedulesByDate[date] || [];

  return (activeStaff || []).filter((staff) => {
    const assignedConflict = (selectedDateBookings || []).some((b) => {
      if (String(b.booking_id) === String(booking.booking_id)) return false;
      if (!b.staff_id) return false;
      if (String(b.staff_id) !== String(staff.staff_id)) return false;
      return overlap(start, end, toMinutes(b.start_time), toMinutes(b.end_time));
    });
    if (assignedConflict) return false;

    const thisStaffShifts = daySchedules.filter((s) => String(s.staff_id) === String(staff.staff_id));
    if (thisStaffShifts.length > 0) {
      return thisStaffShifts.some((s) => overlap(start, end, toMinutes(s.start_time), toMinutes(s.end_time)));
    }

    if (String(staff.staff_type) === "general") {
      return start >= toMinutes("10:00") && end <= toMinutes("18:00");
    }

    return false;
  });
}

function getAvailableRoomsForBooking(booking, rooms, selectedDateBookings) {
  const start = toMinutes(booking.start_time);
  const end = toMinutes(booking.end_time);
  const serviceCategoryId = booking.service_category_id ? String(booking.service_category_id) : null;

  return (rooms || []).filter((room) => {
    if (String(room.status || "").toLowerCase() === "maintenance") return false;
    if (serviceCategoryId && room.category_id && String(room.category_id) !== serviceCategoryId) return false;

    const roomConflict = (selectedDateBookings || []).some((b) => {
      if (String(b.booking_id) === String(booking.booking_id)) return false;
      if (!b.room_id) return false;
      if (String(b.room_id) !== String(room.room_id)) return false;
      return overlap(start, end, toMinutes(b.start_time), toMinutes(b.end_time));
    });

    return !roomConflict;
  });
}

function buildMonthGrid(monthDate) {
  const year = monthDate.getFullYear();
  const month = monthDate.getMonth();
  const first = new Date(year, month, 1);
  const firstIso = first.getDay() === 0 ? 7 : first.getDay();
  const start = new Date(first);
  start.setDate(first.getDate() - (firstIso - 1));

  return Array.from({ length: 42 }, (_, i) => {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    return {
      date: toDateInput(d),
      day: d.getDate(),
      isCurrentMonth: d.getMonth() === month,
    };
  });
}

function startOfWeekISO(dateString) {
  const d = new Date(dateString + "T00:00:00");
  const day = d.getDay() === 0 ? 7 : d.getDay();
  d.setDate(d.getDate() - (day - 1));
  return toDateInput(d);
}

function toDateInput(d) {
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const dd = String(d.getDate()).padStart(2, "0");
  return `${yyyy}-${mm}-${dd}`;
}

function prettyDate(dateStr, withYear = true) {
  const d = new Date(dateStr + "T00:00:00");
  return d.toLocaleDateString("en-MY", { day: "2-digit", month: "short", ...(withYear ? { year: "numeric" } : {}) });
}

function shortTime(t) {
  if (!t) return "-";
  return String(t).slice(0, 5);
}

function toMinutes(t) {
  const [h, m] = String(t || "00:00").slice(0, 5).split(":").map(Number);
  return (h || 0) * 60 + (m || 0);
}

function overlap(aStart, aEnd, bStart, bEnd) {
  return aStart < bEnd && aEnd > bStart;
}

function staffTypeLabel(type) {
  if (String(type) === "general") return "Full-Time";
  if (String(type) === "student") return "Part-Time";
  return humanize(type);
}

function humanize(value) {
  return String(value || "-").replace(/[_-]+/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}
