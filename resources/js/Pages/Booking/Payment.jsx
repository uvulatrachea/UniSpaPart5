import React, { useState } from "react";
import { router, usePage } from "@inertiajs/react";
import CustomerLayout from "@/Layouts/CustomerLayout";

export default function Payment({ preview = [], totalFinal = 0, totalDeposit = 0, qrUploadUrl, appliedPromo = null }) {
  const { errors = {}, flash = {} } = usePage().props;
  const [loadingQr, setLoadingQr] = useState(false);
  const [receipt, setReceipt] = useState(null);
  const [errorMsg, setErrorMsg] = useState(flash?.error || "");

  const payQr = () => {
    if (!receipt) {
      setErrorMsg("Please choose a receipt file first.");
      return;
    }

    setLoadingQr(true);
    setErrorMsg("");
    router.post(
      qrUploadUrl,
      { receipt },
      {
        forceFormData: true,
        onFinish: () => setLoadingQr(false),
        onError: (errs) => {
          const msg = Object.values(errs || {}).flat().join(" ") || "Upload failed. Please try again.";
          setErrorMsg(msg);
        },
      }
    );
  };

  return (
    <CustomerLayout title="Payment" active="booking">
      <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:py-10">
        <div className="ui-card p-5 sm:p-7">
          <h1 className="mb-1 text-2xl font-black text-slate-900 sm:text-3xl">Payment</h1>
          <p className="mb-6 text-sm font-semibold text-slate-600">Complete deposit payment to confirm your booking.</p>

          {errorMsg && (
            <div className="mb-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-700">
              {errorMsg}
            </div>
          )}

          {/* Booking Summary */}
          <div className="mb-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="font-extrabold text-slate-900">Booking Summary</p>
            <p className="mt-1 text-sm font-semibold text-slate-600">Items: {preview.length}</p>
            {appliedPromo && (
              <p className="text-sm font-semibold text-violet-700">Promo ({appliedPromo.code}): - RM {Number(appliedPromo.promo_discount_amount || 0).toFixed(2)}</p>
            )}
            <p className="text-sm font-semibold text-slate-600">Total: RM {Number(totalFinal).toFixed(2)}</p>
            <p className="mt-1 text-sm font-black text-slate-900">Deposit (30%): RM {Number(totalDeposit).toFixed(2)}</p>
          </div>

          {/* QR Payment */}
          <div className="rounded-2xl border border-slate-200 bg-white p-4">
            <p className="mb-2 font-extrabold text-slate-900">Upload QR Payment Receipt</p>
            <img
              src="/images/unispaqr.jpg"
              alt="UniSpa QR"
              className="mb-3 w-full rounded-xl border border-slate-200 object-contain"
            />
            <p className="mb-2 text-xs font-semibold text-slate-500">Scan the QR above, then upload your payment proof below.</p>
            <input
              type="file"
              onChange={(e) => setReceipt(e.target.files?.[0] || null)}
              className="mb-3 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700"
            />
            {receipt ? <p className="mb-2 text-xs font-semibold text-emerald-700">Selected file: {receipt.name}</p> : null}
            {errors?.receipt ? <p className="mb-2 text-xs font-semibold text-rose-600">{errors.receipt}</p> : null}
            <button
              onClick={payQr}
              disabled={loadingQr}
              className="w-full rounded-xl bg-gradient-to-r from-unispa-primaryDark to-unispa-primary px-5 py-3 font-extrabold text-white shadow hover:opacity-95 disabled:opacity-50"
            >
              {loadingQr ? "Uploading..." : "Upload Receipt"}
            </button>
            <p className="mt-2 text-[11px] font-semibold text-slate-500">
              Staff will verify your receipt. Once approved, you'll receive an email confirmation.
            </p>
          </div>
        </div>
      </div>
    </CustomerLayout>
  );
}
