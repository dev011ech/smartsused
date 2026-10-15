import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../../core/network/api_client.dart';
import '../data/ticket_service.dart';

class CreateTicketScreen extends StatefulWidget {
  const CreateTicketScreen({super.key});

  @override
  State<CreateTicketScreen> createState() => _CreateTicketScreenState();
}

class _CreateTicketScreenState extends State<CreateTicketScreen> {
  final _formKey = GlobalKey<FormState>();
  final _titleController = TextEditingController();
  final _descriptionController = TextEditingController();
  final _unitIdController = TextEditingController();
  final _imagePicker = ImagePicker();
  XFile? _image;
  String _importance = 'normal';
  bool _isSubmitting = false;
  double? _uploadProgress;
  String? _errorMessage;

  @override
  void dispose() {
    _titleController.dispose();
    _descriptionController.dispose();
    _unitIdController.dispose();
    super.dispose();
  }

  Future<void> _chooseImage() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Wrap(
          children: [
            ListTile(
              leading: const Icon(LucideIcons.camera),
              title: const Text('Odfotiť fotoaparátom'),
              onTap: () => Navigator.pop(context, ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(LucideIcons.image),
              title: const Text('Vybrať z galérie'),
              onTap: () => Navigator.pop(context, ImageSource.gallery),
            ),
          ],
        ),
      ),
    );
    if (source == null) return;

    try {
      final image = await _imagePicker.pickImage(
        source: source,
        imageQuality: 82,
        maxWidth: 1920,
      );
      if (image != null) {
        final imageSize = await image.length();
        if (!mounted) return;
        if (imageSize > 5 * 1024 * 1024) {
          setState(() {
            _errorMessage =
                'Fotografia môže mať najviac 5 MiB. Vyberte menší obrázok.';
          });
          return;
        }
        setState(() {
          _image = image;
          _errorMessage = null;
        });
      }
    } on PlatformException {
      if (!mounted) return;
      setState(() {
        _errorMessage = 'Prístup k fotoaparátu alebo galérii sa nepodaril. Skontrolujte povolenia a skúste znova.';
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _errorMessage = 'Fotografiu sa nepodarilo vybrať. Skúste to znova.';
      });
    }
  }

  Future<void> _submit() async {
    if (_isSubmitting || !_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _isSubmitting = true;
      _uploadProgress = null;
      _errorMessage = null;
    });

    try {
      await TicketService(context.read<ApiClient>()).create(
        title: _titleController.text.trim(),
        description: _descriptionController.text.trim(),
        unitId: int.parse(_unitIdController.text.trim()),
        importance: _importance,
        image: _image,
        onSendProgress: (sent, total) {
          if (mounted && total > 0) {
            setState(() => _uploadProgress = sent / total);
          }
        },
      );
      if (!mounted) return;

      await showDialog<void>(
        context: context,
        barrierDismissible: false,
        builder: (context) => AlertDialog(
          icon: Icon(
            LucideIcons.circleCheck,
            color: Theme.of(context).colorScheme.primary,
            size: 44,
          ),
          title: const Text('Nahlásenie odoslané'),
          content: const Text(
            'Správca dostal vaše nahlásenie poruchy. O zmene stavu vás budeme informovať.',
          ),
          actions: [
            FilledButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Pokračovať'),
            ),
          ],
        ),
      );
      if (mounted) context.go('/dashboard');
    } on DioException catch (error) {
      if (!mounted) return;
      final data = error.response?.data;
      setState(() {
        _errorMessage =
            data is Map<String, dynamic> && data['message'] is String
            ? data['message'] as String
            : 'Nahlásenie sa nepodarilo odoslať. Skontrolujte pripojenie a skúste znova.';
      });
    } on FormatException catch (error) {
      if (mounted) setState(() => _errorMessage = error.message);
    } finally {
      if (mounted) {
        setState(() {
          _isSubmitting = false;
          _uploadProgress = null;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final canSubmit =
        !_isSubmitting && _descriptionController.text.trim().isNotEmpty;

    return Form(
      key: _formKey,
      onChanged: () => setState(() {}),
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            'Nahlásiť poruchu',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 8),
          Text(
            'Popíšte problém a priložte fotografiu, ak pomôže s jeho posúdením.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 20),
          TextFormField(
            controller: _titleController,
            maxLength: 120,
            textInputAction: TextInputAction.next,
            decoration: const InputDecoration(
              labelText: 'Nadpis poruchy',
              hintText: 'Napríklad: Nefunguje výťah',
              border: OutlineInputBorder(),
              isDense: true,
            ),
            validator: _required,
          ),
          const SizedBox(height: 12),
          TextFormField(
            controller: _descriptionController,
            maxLines: 4,
            maxLength: 2000,
            textInputAction: TextInputAction.next,
            decoration: const InputDecoration(
              labelText: 'Stručný popis poruchy',
              hintText: 'Čo sa stalo a kde?',
              border: OutlineInputBorder(),
              alignLabelWithHint: true,
              isDense: true,
            ),
            validator: _required,
          ),
          const SizedBox(height: 12),
          TextFormField(
            controller: _unitIdController,
            keyboardType: TextInputType.number,
            textInputAction: TextInputAction.done,
            decoration: const InputDecoration(
              labelText: 'ID bytovej jednotky',
              border: OutlineInputBorder(),
              isDense: true,
            ),
            validator: (value) {
              final unitId = int.tryParse(value?.trim() ?? '');
              if (unitId == null || unitId < 1) {
                return 'Zadajte platné číselné ID bytovej jednotky.';
              }
              return null;
            },
          ),
          const SizedBox(height: 20),
          Text('Dôležitosť', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 4),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(8),
              child: SegmentedButton<String>(
                segments: const [
                  ButtonSegment(value: 'normal', label: Text('Bežná porucha')),
                  ButtonSegment(value: 'emergency', label: Text('Havária')),
                ],
                selected: {_importance},
                onSelectionChanged: _isSubmitting
                    ? null
                    : (selection) =>
                          setState(() => _importance = selection.first),
              ),
            ),
          ),
          const SizedBox(height: 16),
          Text('Fotografia', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 8),
          _DashedPhotoPicker(
            image: _image,
            enabled: !_isSubmitting,
            onTap: _chooseImage,
            onRemove: () => setState(() => _image = null),
          ),
          if (_errorMessage != null) ...[
            const SizedBox(height: 16),
            Semantics(
              liveRegion: true,
              child: Text(
                _errorMessage!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
          ],
          const SizedBox(height: 24),
          FilledButton.icon(
            onPressed: canSubmit ? _submit : null,
            icon: _isSubmitting
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  )
                : const Icon(LucideIcons.send, size: 20),
            label: Text(
              _isSubmitting
                  ? _uploadProgress == null
                        ? 'Odosielam…'
                        : 'Nahrávam ${(_uploadProgress! * 100).round()} %'
                  : 'Odoslať správcovi',
            ),
          ),
        ],
      ),
    );
  }

  String? _required(String? value) =>
      value == null || value.trim().isEmpty ? 'Toto pole je povinné.' : null;
}

class _DashedPhotoPicker extends StatelessWidget {
  const _DashedPhotoPicker({
    required this.image,
    required this.enabled,
    required this.onTap,
    required this.onRemove,
  });

  final XFile? image;
  final bool enabled;
  final VoidCallback onTap;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      painter: _DashedBorderPainter(
        color: Theme.of(context).colorScheme.outline,
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: image == null && enabled ? onTap : null,
          borderRadius: BorderRadius.circular(12),
          child: SizedBox(
            height: 190,
            width: double.infinity,
            child: image == null
                ? const Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(LucideIcons.camera, size: 32),
                      SizedBox(height: 8),
                      Text('Pridať fotografiu'),
                      SizedBox(height: 4),
                      Text('Fotoaparát alebo galéria'),
                    ],
                  )
                : ClipRRect(
                    borderRadius: BorderRadius.circular(12),
                    child: Stack(
                      fit: StackFit.expand,
                      children: [
                        Image.file(File(image!.path), fit: BoxFit.cover),
                        Positioned(
                          top: 8,
                          right: 8,
                          child: IconButton.filledTonal(
                            tooltip: 'Odstrániť fotografiu',
                            onPressed: enabled ? onRemove : null,
                            icon: const Icon(LucideIcons.trash2, size: 18),
                          ),
                        ),
                        Positioned(
                          left: 8,
                          bottom: 8,
                          child: Chip(
                            avatar: const Icon(LucideIcons.image, size: 16),
                            label: Text(image!.name),
                            visualDensity: VisualDensity.compact,
                          ),
                        ),
                      ],
                    ),
                  ),
          ),
        ),
      ),
    );
  }
}

class _DashedBorderPainter extends CustomPainter {
  const _DashedBorderPainter({required this.color});

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5;
    final path = Path()
      ..addRRect(
        RRect.fromRectAndRadius(Offset.zero & size, const Radius.circular(12)),
      );
    for (final metric in path.computeMetrics()) {
      var distance = 0.0;
      while (distance < metric.length) {
        final end = (distance + 7).clamp(0, metric.length).toDouble();
        canvas.drawPath(metric.extractPath(distance, end), paint);
        distance = end + 5;
      }
    }
  }

  @override
  bool shouldRepaint(covariant _DashedBorderPainter oldDelegate) =>
      oldDelegate.color != color;
}
