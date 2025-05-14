<?php
namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    // Message sending functionality
    public function sendMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receiver_id' => 'required|exists:users,id',
            'message'     => 'required|string',
            'image'       => 'nullable|file|image|max:2048', // Added max size to image validation
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()], 422);
        }

        $sender = Auth::user();
        $receiver = User::find($request->receiver_id);

        if (!$receiver) {
            return response()->json(['status' => false, 'message' => 'Receiver not found.'], 404);
        }

        // Role-based sending restrictions
        if ($sender->role === 'user' && $receiver->role !== 'provider') {
            return response()->json(['status' => false, 'message' => 'Users can only send messages to providers.'], 403);
        }

        if ($sender->role === 'super_admin' && $receiver->role !== 'provider') {
            return response()->json(['status' => false, 'message' => 'Superadmin can only send messages to providers.'], 403);
        }

        if ($sender->role === 'provider' && ! in_array($receiver->role, ['user', 'super_admin'])) {
            return response()->json(['status' => false, 'message' => 'Providers can only send messages to users or admins.'], 403);
        }

        $new_name = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $extension = $image->getClientOriginalExtension();
            $new_name = time() . '.' . $extension;
            $image->move(public_path('uploads/message_images'), $new_name);
        }

        try {
            $message = Message::create([
                'sender_id'   => $sender->id,
                'receiver_id' => $receiver->id,
                'message'     => $request->message,
                'image'       => $new_name,
                'is_read'     => false,
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Message sent successfully!',
                'data'    => $message,
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Error sending message: ' . $e->getMessage()], 500);
        }
    }

    // Get messages between sender and receiver
    public function getMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receiver_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $sender_id = Auth::id();
        $receiver_id = $request->receiver_id;

        $messages = Message::where(function ($query) use ($sender_id, $receiver_id) {
            $query->where('sender_id', $sender_id)->where('receiver_id', $receiver_id);
        })->orWhere(function ($query) use ($sender_id, $receiver_id) {
            $query->where('sender_id', $receiver_id)->where('receiver_id', $sender_id);
        })->orderBy('created_at', 'asc')->paginate(20); // Added pagination limit

        return response()->json(['status' => true, 'data' => $messages], 200);
    }

    // Mark message as read
    public function readMessage(Request $request)
    {
        $sender_id = $request->sender_id;
        $receiver_id = Auth::id();

        if ($sender_id == $receiver_id) {
            return response(['status' => false, 'message' => 'You cannot mark your own sent messages as read.'], 401);
        }

        try {
            $message = Message::where('sender_id', $sender_id)
                ->where('receiver_id', $receiver_id)
                ->where('is_read', 0)
                ->update(['is_read' => 1]);

            if ($message) {
                return response(['status' => true, 'message' => 'Message read successfully']);
            }

            return response(['status' => false, 'message' => 'No unread messages found.'], 422);
        } catch (\Exception $e) {
            return response(['status' => false, 'message' => 'Error marking message as read: ' . $e->getMessage()], 500);
        }
    }

    // Search users by name
    public function searchUser(Request $request)
    {
        $users = User::where('full_name', 'like', '%' . $request->search . '%')->get();

        if ($users->isEmpty()) {
            return response()->json(['status' => false, 'message' => 'No users found matching the search criteria.']);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Users found',
            'data'    => $users,
        ]);
    }

    // Get message list
    public function messageList(Request $request)
    {
        $user_id = Auth::id();
        $role = $request->role;
        $search = $request->search;

        $message_list = Message::with(['receiver:id,full_name,image', 'sender:id,full_name,image'])
            ->where(function ($query) use ($user_id) {
                $query->where('sender_id', $user_id)
                    ->orWhere('receiver_id', $user_id);
            });

        if ($role) {
            $message_list->whereHas('receiver', function ($query) use ($role, $search) {
                $query->where('role', $role);
                if ($search) {
                    $query->where('full_name', 'like', '%' . $search . '%');
                }
            });
        }

        // Fetch latest messages and remove duplicates based on sender/receiver pair
        $message_list = $message_list->latest('created_at')->get()->unique(function ($msg) use ($user_id) {
            return $msg->sender_id === $user_id ? $msg->receiver_id : $msg->sender_id;
        })->values();

        return response()->json([
            'status'  => true,
            'message' => 'Messages fetched successfully.',
            'data'    => $message_list,
        ]);
    }
}

