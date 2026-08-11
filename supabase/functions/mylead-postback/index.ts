import { serve } from "https://deno.land/std@0.168.0/http/server.ts";
import { createClient } from "https://esm.sh/@supabase/supabase-js@2";

const CONVERSION_RATE = 500000; // 500k pts = $1.00

serve(async (req) => {
  const url = new URL(req.url);
  
  // MyLead sends these params
  const player_id = url.searchParams.get("player_id") || url.searchParams.get("ml_sub1") || "";
  const payout = url.searchParams.get("payout_decimal") || url.searchParams.get("payout") || "0";
  const status = url.searchParams.get("status") || "";
  const trans_id = url.searchParams.get("transaction_id") || url.searchParams.get("transactionId") || "";
  const currency = url.searchParams.get("currency") || "USD";

  // Validate
  if (!player_id) return new Response("ERROR: Missing player_id/ml_sub1", { status: 400 });
  if (!trans_id) return new Response("ERROR: Missing transaction_id", { status: 400 });
  if (!payout || parseFloat(payout) <= 0) return new Response("OK: Zero payout", { status: 200 });

  const validStatuses = ["approved", "completed", "1", "active", "accepted", "available to pay"];
  if (!validStatuses.includes(status.toLowerCase())) {
    return new Response(`OK: Status '${status}' not eligible`, { status: 200 });
  }

  const supabase = createClient(
    Deno.env.get("SUPABASE_URL")!,
    Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!
  );

  // Check duplicate
  const { data: dup } = await supabase
    .from("mylead_completions")
    .select("id")
    .eq("trans_id", trans_id)
    .maybeSingle();

  if (dup) return new Response("OK: Duplicate transaction", { status: 200 });

  // Find user
  const { data: user } = await supabase
    .from("profiles")
    .select("id, points")
    .eq("id", player_id)
    .maybeSingle();

  if (!user) return new Response(`ERROR: User not found: ${player_id}`, { status: 404 });

  const points = Math.floor(parseFloat(payout) * CONVERSION_RATE);
  const newPoints = (user.points || 0) + points;

  // Log completion
  await supabase.from("mylead_completions").insert({
    user_id: user.id,
    trans_id: trans_id,
    reward_points: points,
    usd_value: parseFloat(payout),
    status: "completed"
  });

  // Credit points
  const { error } = await supabase
    .from("profiles")
    .update({ points: newPoints })
    .eq("id", user.id);

  if (error) return new Response(`ERROR: ${error.message}`, { status: 500 });

  return new Response(`OK: Credited ${points} pts to ${user.id} ($${payout})`, { status: 200 });
});
