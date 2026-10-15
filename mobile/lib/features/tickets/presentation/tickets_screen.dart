import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../../core/network/api_client.dart';
import '../data/ticket_service.dart';

class TicketsScreen extends StatefulWidget {
  const TicketsScreen({super.key});

  @override
  State<TicketsScreen> createState() => _TicketsScreenState();
}

class _TicketsScreenState extends State<TicketsScreen> {
  final _formKey = GlobalKey<FormState>();
  final _titleController = TextEditingController();
  final _descriptionController = TextEditingController();
  final _unitIdController = TextEditingController();
  bool _isSubmitting = false;
  String? _message;
  bool _hasError = false;

  @override
  void dispose() {
    _titleController.dispose();
    _descriptionController.dispose();
    _unitIdController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isSubmitting = true;
      _message = null;
      _hasError = false;
    });

    try {
      await TicketService(context.read<ApiClient>()).create(
        title: _titleController.text.trim(),
        description: _descriptionController.text.trim(),
        unitId: int.parse(_unitIdController.text.trim()),
        importance: 'normal',
      );
      if (!mounted) return;
      _formKey.currentState!.reset();
      _titleController.clear();
      _descriptionController.clear();
      _unitIdController.clear();
      setState(() {
        _hasError = false;
        _message = 'Porucha bola úspešne nahlásená.';
      });
    } on DioException catch (error) {
      if (!mounted) return;
      final data = error.response?.data;
      setState(() {
        _hasError = true;
        _message = data is Map<String, dynamic> && data['message'] is String
            ? data['message'] as String
            : 'Nahlásenie sa nepodarilo. Skúste to znova.';
      });
    } on FormatException catch (error) {
      if (!mounted) return;
      setState(() {
        _hasError = true;
        _message = error.message;
      });
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Text(
          'Nahlásiť poruchu',
          style: Theme.of(context).textTheme.headlineMedium,
        ),
        const SizedBox(height: 8),
        const Text('Popíšte problém a uveďte číslo vašej bytovej jednotky.'),
        const SizedBox(height: 20),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  TextFormField(
                    controller: _titleController,
                    maxLength: 120,
                    decoration: const InputDecoration(
                      labelText: 'Nadpis poruchy',
                    ),
                    validator: _required,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _descriptionController,
                    maxLines: 5,
                    maxLength: 2000,
                    decoration: const InputDecoration(
                      labelText: 'Popis poruchy',
                    ),
                    validator: _required,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _unitIdController,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'ID bytovej jednotky',
                    ),
                    validator: (value) {
                      if (value == null || int.tryParse(value.trim()) == null) {
                        return 'Zadajte číselné ID bytovej jednotky.';
                      }
                      if (int.parse(value.trim()) < 1) {
                        return 'ID bytovej jednotky musí byť väčšie ako nula.';
                      }
                      return null;
                    },
                  ),
                  if (_message != null) ...[
                    const SizedBox(height: 16),
                    Semantics(
                      liveRegion: true,
                      child: Text(
                        _message!,
                        style: TextStyle(
                          color: _hasError
                              ? Theme.of(context).colorScheme.error
                              : Theme.of(context).colorScheme.primary,
                        ),
                      ),
                    ),
                  ],
                  const SizedBox(height: 20),
                  FilledButton.icon(
                    onPressed: _isSubmitting ? null : _submit,
                    icon: _isSubmitting
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(LucideIcons.send, size: 22),
                    label: Text(
                      _isSubmitting ? 'Odosielam…' : 'Odoslať nahlásenie',
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }

  String? _required(String? value) =>
      value == null || value.trim().isEmpty ? 'Toto pole je povinné.' : null;
}
